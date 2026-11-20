<?php

declare(strict_types=1);

namespace DbCms\Modules\ContentAudit\Observers;

use DbCms\Core\Capabilities\ExecutionContext;
use DbCms\Core\Contracts\Services\ModuleSettingsServiceInterface;
use DbCms\Core\Services\Privacy\LegalPageService;
use DbCms\Modules\ContentAudit\Enums\AuditAction;
use DbCms\Modules\ContentAudit\Services\AuditRevisionService;
use DbCms\Modules\ContentAudit\Services\EditorialQualityGate;
use DbCms\Modules\ContentAudit\Services\EditorialWorkflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ContentAuditObserver
{
    protected static bool $isHandling = false;

    public function saving(Model $model): void
    {
        if (static::$isHandling || (app()->runningInConsole() && ! app()->runningUnitTests() && app(ExecutionContext::class)->actor === null)) {
            return;
        }

        /** @var EditorialWorkflow $auditService */
        $auditService = app(EditorialWorkflow::class);
        if (! $auditService->isEnabled()) {
            return;
        }

        $user = Auth::user();
        if (! $user) {
            return;
        }

        try {
            app(LegalPageService::class)->requireProcessing('content_audit', true);
        } catch (ValidationException) {
            throw ValidationException::withMessages(['privacy' => __('content_audit::privacy.processing_required')]);
        }
        $auditAction = (string) (request()?->input('audit_action') ?? '');
        $requiresAudit = $auditService->requiresAudit($model, $user);

        // Binding editorial quality gate (configurable): blocks the save with
        // concrete recommendations when the content does not meet the bar.
        app(EditorialQualityGate::class)->enforceRequest($model, $user, $requiresAudit);

        if ($requiresAudit || $auditAction === 'submit_for_review') {
            $fillable = $model->getFillable();
            if (in_array('is_published', $fillable, true)) {
                $model->setAttribute('is_published', false);
            } elseif (in_array('is_active', $fillable, true)) {
                $model->setAttribute('is_active', false);
            }
            if (in_array('published_at', $fillable, true)) {
                $model->setAttribute('published_at', null);
            }
        }
    }

    public function saved(Model $model): void
    {
        if (static::$isHandling || (app()->runningInConsole() && ! app()->runningUnitTests() && app(ExecutionContext::class)->actor === null)) {
            return;
        }

        /** @var EditorialWorkflow $auditService */
        $auditService = app(EditorialWorkflow::class);
        if (! $auditService->isEnabled()) {
            return;
        }

        $user = Auth::user();
        if (! $user) {
            return;
        }

        static::$isHandling = true;
        try {
            $auditAction = (string) (request()?->input('audit_action') ?? '');
            $requiresAudit = $auditService->requiresAudit($model, $user);

            if ($auditAction === 'draft') {
                $auditService->saveAsDraft($model, $user);
                if (session()) {
                    session()->flash('info', 'Contenido guardado como borrador sin enviar a revisión.');
                }
            } elseif ($requiresAudit || $auditAction === 'submit_for_review') {
                $notes = request()?->input('audit_notes');
                $auditService->submitForReview($model, $user, is_string($notes) ? $notes : null);
                if (session()) {
                    session()->flash('info', 'Contenido guardado y enviado a revisión editorial.');
                }
            } elseif ((bool) app(ModuleSettingsServiceInterface::class)->get('content_audit', 'track_all_modifications', true)) {
                $attrs = $model->getAttributes();
                $isPublished = (bool) ($attrs['is_published'] ?? $attrs['is_active'] ?? false);
                $action = $model->wasRecentlyCreated
                    ? ($isPublished ? AuditAction::PUBLISHED->value : AuditAction::CREATED->value)
                    : ($isPublished ? AuditAction::PUBLISHED->value : AuditAction::UPDATED->value);
                $status = $isPublished ? 'published' : 'draft';

                app(AuditRevisionService::class)->record($model, $user, $action, null, $status);
            }
        } finally {
            static::$isHandling = false;
        }
    }
}
