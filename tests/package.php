<?php

declare(strict_types=1);

return [
    'laravel' => [
        'version' => '13.11.0',
        'reference' => '06d016a364a37430eec9cbc52209adce3d7667ce',
    ],
    'kind' => 'module',
    'core' => '0.0.1',
    'fixtureVersion' => '0.0.1',
    'theme' => 'default-clean',
    'adminTheme' => 'dbcms',
    'require' => [
    ],
    'sourceDirs' => [
        0 => '.',
    ],
    'testPaths' => [
        0 => 'Architecture/ModuleCapabilityOwnershipTest.php',
        1 => 'Unit/Services/EditorialReviewPresenterTest.php',
        2 => 'Feature/Privacy/LegalPageAdoptionTest.php',
        3 => 'Feature/Admin/FormEditorialPreviewTest.php',
        4 => 'Feature/ContentAudit/EditorialPrivacyConfigurationTest.php',
        5 => 'Feature/ContentAudit/AuditPrivacyTest.php',
        6 => 'Feature/Cms/LocalizedLayoutReviewTest.php',
        7 => 'Feature/Cms/PerLanguageSeoTest.php',
        8 => 'Feature/ContentAudit/RenderedComparisonTest.php',
    ],
    'modules' => [
        0 => 'content_audit',
    ],
    'blockPackages' => [
    ],
    'privacyProcessing' => [
    ],
    'preserveRows' => [
    ],
    'profiles' => [
        'integration' => [
            'require' => [
                'dbravoan/dbcms-module-analytics' => '0.0.1',
                'dbravoan/dbcms-module-blog' => '0.0.1',
                'dbravoan/dbcms-module-gallery' => '0.0.1',
                'dbravoan/dbcms-module-popups' => '0.0.1',
                'dbravoan/dbcms-theme-default-clean' => '0.0.1',
                'dbravoan/dbcms-theme-db-cyber' => '0.0.1',
                'dbravoan/dbcms-theme-db-editorial' => '0.0.1',
                'dbravoan/dbcms-theme-db-minimal' => '0.0.1',
                'dbravoan/dbcms-theme-db-sunset' => '0.0.1',
                'dbravoan/dbcms-theme-db-brutalist' => '0.0.1',
                'dbravoan/dbcms-theme-db-enterprise' => '0.0.1',
                'dbravoan/dbcms-admin-theme-termocms' => '0.0.1',
            ],
            'testPaths' => [
                0 => 'Feature/Mcp/McpPublishHonestStateTest.php',
                1 => 'Feature/Mcp/McpBackofficeCapabilitiesTest.php',
                2 => 'Feature/Mcp/McpPassportM2mTest.php',
                3 => 'Feature/Mcp/McpM2mAuditTest.php',
                4 => 'Feature/Admin/FormBuilderAndManagerTest.php',
                5 => 'Feature/Admin/UnifiedListingsTest.php',
                6 => 'Feature/ContentAudit/ContentAuditCacheInvalidationTest.php',
                7 => 'Feature/ContentAudit/EditorialEnforcementTest.php',
                8 => 'Feature/ContentAudit/OwnerEditorialPreviewTest.php',
                9 => 'Feature/ContentAudit/ContentAuditSelfUnlockTest.php',
                10 => 'Feature/ContentAudit/ContentAuditChangelogQueryTest.php',
                11 => 'Feature/ContentAudit/ComposableBlockPatchTest.php',
                12 => 'Feature/ContentAudit/ContentAuditDraftButtonTest.php',
                13 => 'Feature/ContentAudit/ContentAuditModuleTest.php',
                14 => 'Feature/ContentAudit/ContentAuditReliabilityTest.php',
                15 => 'Feature/Auth/ContentMutationBoundaryTest.php',
                16 => 'Feature/Auth/BackofficeMinimumPrivilegeTest.php',
                17 => 'Feature/Performance/FrontendPerformanceBudgetTest.php',
                18 => 'Feature/Ui/BlockEditorCompositionTest.php',
                19 => 'Feature/Cms/EditorialMutationParityTest.php',
                20 => 'Feature/Cms/PostBlockManagerAndSectionsTest.php',
                21 => 'Feature/Cms/BackofficeCrudConsistencyTest.php',
                22 => 'Feature/Cms/BackofficeMultilingualEditorTest.php',
                23 => 'Feature/Cms/AdminThemeEngineTest.php',
            ],
        ],
    ],
];
