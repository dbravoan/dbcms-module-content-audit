<?php

declare(strict_types=1);

namespace DbCms\Modules\ContentAudit;

use DbCms\Core\Capabilities\CapabilityRegistry;
use DbCms\Core\Capabilities\Contracts\ContentRevisionInspectorInterface;
use DbCms\Core\Capabilities\Resources\CapabilityResourceRegistry;
use DbCms\Core\Content\Contracts\EditorialWorkflowInterface;
use DbCms\Core\Content\Contracts\EntitySchemaRegistryInterface;
use DbCms\Core\Events\PrivacyConfigurationValidating;
use DbCms\Core\Models\User;
use DbCms\Core\Modules\BaseModuleServiceProvider;
use DbCms\Core\Modules\ModuleManager;
use DbCms\Core\Notifications\NotificationRecipientPolicyRegistry;
use DbCms\Core\Services\Privacy\LegalPageService;
use DbCms\Modules\ContentAudit\Capabilities\AuditResources;
use DbCms\Modules\ContentAudit\Capabilities\EditorialActionCatalog;
use DbCms\Modules\ContentAudit\Capabilities\EditorialActions;
use DbCms\Modules\ContentAudit\Console\AuditPrivacyCommand;
use DbCms\Modules\ContentAudit\Contracts\Services\AuditWorkbenchServiceInterface;
use DbCms\Modules\ContentAudit\Contracts\Services\DiffEngineServiceInterface;
use DbCms\Modules\ContentAudit\Contracts\Services\EditorialVisibilityInterface;
use DbCms\Modules\ContentAudit\Models\ContentAudit;
use DbCms\Modules\ContentAudit\Models\ContentAuditRevision;
use DbCms\Modules\ContentAudit\Observers\ContentAuditObserver;
use DbCms\Modules\ContentAudit\Services\AuditNotificationRecipientPolicy;
use DbCms\Modules\ContentAudit\Services\AuditWorkbenchService;
use DbCms\Modules\ContentAudit\Services\ContentRevisionInspector;
use DbCms\Modules\ContentAudit\Services\DiffEngineService;
use DbCms\Modules\ContentAudit\Services\EditorialPolicy;
use DbCms\Modules\ContentAudit\Services\EditorialVisibility;
use DbCms\Modules\ContentAudit\Services\EditorialWorkflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;

class ContentAuditModuleServiceProvider extends BaseModuleServiceProvider
{
    protected string $identifier = 'content_audit';

    protected string $name = 'Auditoría de Contenidos & Control de Calidad';

    protected bool $isPageable = false;

    public function register(): void
    {
        $this->app->bind(ContentRevisionInspectorInterface::class, ContentRevisionInspector::class);
        $this->app->bind(EditorialWorkflowInterface::class, EditorialWorkflow::class);
        $this->app->singleton(DiffEngineServiceInterface::class, DiffEngineService::class);
        $this->app->bind(EditorialVisibilityInterface::class, EditorialVisibility::class);
        $this->app->bind(AuditWorkbenchServiceInterface::class, AuditWorkbenchService::class);
    }

    public function boot(): void
    {
        $this->commands([AuditPrivacyCommand::class]);
        $this->app->booted(function (): void {
            EditorialActionCatalog::register(
                $this->app->make(CapabilityRegistry::class),
                $this->app->make(EditorialActions::class),
            );
        });
        $this->app->make(CapabilityResourceRegistry::class)->register(AuditResources::descriptor());
        $this->app->make(NotificationRecipientPolicyRegistry::class)->register('content_audit', $this->app->make(AuditNotificationRecipientPolicy::class));
        parent::boot();

        $this->loadViewsFrom(__DIR__.'/Resources/views', 'content_audit');
        $this->loadTranslationsFrom(__DIR__.'/Resources/lang', 'content_audit');
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        Event::listen(PrivacyConfigurationValidating::class, static function (PrivacyConfigurationValidating $event): void {
            foreach ($event->company->values['processing'] as $entry) {
                if ($entry['owner'] === 'content_audit' && (int) ($entry['retention_days'] ?? 0) < 1 && ! ($entry['manual_review'] ?? false)) {
                    throw ValidationException::withMessages(['privacy' => __('content_audit::privacy.retention_required')]);
                }
            }
        });

        View::composer('admin.modules.index', function ($view): void {
            $missing = $this->app->make(ModuleManager::class)->isModuleEnabled('content_audit')
                && ! $this->app->make(LegalPageService::class)->canCollect('content_audit', true);
            if ($missing) {
                $view->with('modulePrivacyWarnings', ['content_audit' => [
                    'message' => __('content_audit::privacy.processing_required'),
                    'label' => __('content_audit::privacy.configure'),
                ]]);
            }
        });

        $this->app->booted(fn () => $this->registerAuditableEntities());
    }

    protected function registerAuditableEntities(): void
    {
        foreach ($this->app->make(EntitySchemaRegistryInterface::class)->all() as $schema) {
            /** @var class-string<Model> $modelClass */
            $modelClass = $schema->model;
            $modelClass::observe(ContentAuditObserver::class);
            $modelClass::resolveRelationUsing('contentAudit', fn (Model $model) => $model->morphOne(ContentAudit::class, 'auditable'));
            $modelClass::resolveRelationUsing('auditRevisions', fn (Model $model) => $model->morphMany(ContentAuditRevision::class, 'auditable')->orderByDesc('version'));
            if ($schema->adminFormView === null) {
                continue;
            }
            View::composer($schema->adminFormView, function ($view) use ($modelClass): void {
                if (! $this->app->make(EditorialPolicy::class)->isEnabled()) {
                    return;
                }
                foreach ($view->getData() as $entity) {
                    if ($entity instanceof $modelClass && $entity->exists) {
                        $view->getFactory()->startPush('admin_form_sidebar', view('content_audit::admin.partials.audit-card', ['entity' => $entity] + $this->app->make(AuditWorkbenchServiceInterface::class)->sidebar($entity, auth()->user()))->render());
                        break;
                    }
                }
            });
        }
    }

    public function getAdminNavigation(): array
    {
        $user = auth()->user();
        $pending = $user instanceof User ? $this->app->make(EditorialWorkflowInterface::class)->pendingReviewCount($user) : 0;

        return [
            [
                'title' => __('admin.nav.items.content_audit'),
                'route' => 'admin.audit.index',
                'icon' => 'clipboard-check',
                'permission' => 'access:admin',
                'badge' => $pending > 0 ? (string) $pending : null,
            ],
        ];
    }
}
