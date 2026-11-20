<?php

declare(strict_types=1);

namespace DbCms\Modules\ContentAudit\Console;

use DbCms\Modules\ContentAudit\Services\AuditPrivacyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class AuditPrivacyCommand extends Command
{
    protected $signature = 'content-audit:privacy {action : export, erase or prune} {kind=revisions : revisions or histories} {--id=* : Explicit owned record IDs} {--verified : Identity, scope and third-party rights were checked} {--retention-released : Documented retention review permits release}';

    protected $description = 'Manage retained editorial history without breaking pending review or restoration';

    public function handle(AuditPrivacyService $privacy): int
    {
        $action = $this->argument('action');
        $kind = $this->argument('kind');
        if (! in_array($kind, ['revisions', 'histories'], true)) {
            $this->error('Kind must be revisions or histories.');

            return self::FAILURE;
        }
        try {
            if ($action === 'prune' && $kind === 'revisions') {
                $this->info('Removed '.$privacy->prune().' expired superseded revisions.');

                return self::SUCCESS;
            }
            $ids = $this->option('id');
            if (! in_array($action, ['export', 'erase'], true) || ! $this->option('verified') || ! is_array($ids) || $ids === [] || count($ids) > 1000 || array_filter($ids, static fn (mixed $id): bool => filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id < 1) !== []) {
                $this->error('Use explicit --id values and --verified after reviewing identity, scope and third-party information.');

                return self::FAILURE;
            }
            $ids = array_values(array_unique(array_map('intval', $ids)));
            if ($action === 'erase') {
                $count = $kind === 'histories' ? $privacy->eraseHistories($ids, (bool) $this->option('retention-released')) : $privacy->eraseRevisions($ids, (bool) $this->option('retention-released'));
                $this->info('Removed '.$count.' owned records.');

                return self::SUCCESS;
            }
            $path = 'privacy/content-audit-'.Str::uuid().'.json';
            Storage::disk('local')->put($path, json_encode($privacy->export($kind, $ids), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'private');
            $this->info('Private export: '.Storage::disk('local')->path($path).'. Review third-party content before delivery and delete after fulfillment.');

            return self::SUCCESS;
        } catch (\DomainException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
