<?php

declare(strict_types=1);

namespace OCA\IntegrationImmich\Tests\Unit;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Test\TestCase;

class SafetyGuardrailTest extends TestCase {
    private const MUTATING_VERBS = [
        'POST' => true,
        'PUT' => true,
        'DELETE' => true,
    ];

    /**
     * Production comments may document an otherwise forbidden token only when the
     * reference is deliberately marked as non-executable guardrail evidence.
     */
    private const ALLOWLIST_MARKERS = [
        'SAFETY-GUARDRAIL-ALLOW',
        '@safety-guardrail-allow',
    ];

    private const SHELL_PROCESS_PATTERNS = [
        'shell_exec' => '/\bshell_exec\s*\(/',
        'exec' => '/\bexec\s*\(/',
        'proc_open' => '/\bproc_open\s*\(/',
        'passthru' => '/\bpassthru\s*\(/',
        'Symfony\\Component\\Process' => '/Symfony\\\\Component\\\\Process\b/',
        'occ files_' => '/\bocc\s+files_/i',
    ];

    private const SYMLINK_PATTERNS = [
        'symlink' => '/\bsymlink\s*\(/',
    ];

    public function testNoShellExecutionInProduction(): void {
        $violations = $this->scanProductionFiles(self::SHELL_PROCESS_PATTERNS);

        $this->assertSame(
            [],
            $violations,
            "Forbidden shell/process/occ usage found in production code:\n" . implode("\n", $violations)
        );
    }

    public function testNoSymlinkCreationInProduction(): void {
        $violations = $this->scanProductionFiles(self::SYMLINK_PATTERNS);

        $this->assertSame(
            [],
            $violations,
            "Forbidden symlink creation found in production code:\n" . implode("\n", $violations)
        );
    }

    public function testMutatingRoutesDoNotUseNoCsrfRequired(): void {
        $mutatingRoutesByController = $this->mutatingRoutesByController();
        $this->assertNotEmpty(
            $mutatingRoutesByController,
            'Expected appinfo/routes.php to define POST/PUT/DELETE routes; the route parser may be stale.'
        );

        $violations = [];
        foreach (self::controllerFiles() as $filePath) {
            $controllerKey = self::controllerKeyFromPath($filePath);
            if (!isset($mutatingRoutesByController[$controllerKey])) {
                continue;
            }

            $relativePath = self::relativePath($filePath);
            $content = self::readFile($filePath);
            array_push(
                $violations,
                ...self::findNoCsrfRequiredMutatingMethodsInContent(
                    $relativePath,
                    $content,
                    $mutatingRoutesByController[$controllerKey]
                )
            );
        }

        $this->assertSame(
            [],
            $violations,
            "Mutating controller routes must remain CSRF-protected:\n" . implode("\n", $violations)
        );
    }

    public function testAdminConfigValidationAndPayloadGuardrailsStayWired(): void {
        $package = json_decode(
            self::readFile(self::projectRoot() . DIRECTORY_SEPARATOR . 'package.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $verifySafetyScript = (string)($package['scripts']['verify:safety'] ?? '');

        $this->assertStringContainsString('verify:admin-settings-payload', $verifySafetyScript);
        $this->assertStringContainsString('verify:localization', $verifySafetyScript);
        $this->assertSame('node scripts/verify-admin-settings-payload.mjs', $package['scripts']['verify:admin-settings-payload'] ?? null);
        $this->assertSame('node scripts/verify-localization-guardrail.mjs', $package['scripts']['verify:localization'] ?? null);

        $payloadScript = self::readFile(self::projectRoot() . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify-admin-settings-payload.mjs');
        $this->assertStringContainsString('disabledProvisioningForcesStorageBooleansFalse', $payloadScript);
        $this->assertStringContainsString('blankAdminApiKeyOmitted', $payloadScript);
        $this->assertStringContainsString('pathTemplatesPreserved', $payloadScript);
        $this->assertStringContainsString('nonBlankAdminApiKeyPreserved', $payloadScript);
        $this->assertStringContainsString("adminSettingsSource.includes('@update:checked')", $payloadScript);
        $this->assertStringContainsString('assertRadioGroupBindings(adminSettingsSource', $payloadScript);
        $this->assertStringContainsString("{ field: 'initial_password_policy', values: ['random', 'sso_oidc'] }", $payloadScript);
        $this->assertStringContainsString("assertInitialPasswordPolicyValidation('[redacted]', 'invalid_enum')", $payloadScript);

        $adminConfigSource = self::readFile(self::projectRoot() . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'Service' . DIRECTORY_SEPARATOR . 'AdminConfigService.php');
        $this->assertStringContainsString('$effectiveValues = $this->normaliseEffectivePathFeatureFlags($values);', $adminConfigSource);
        $this->assertStringContainsString('$pathTemplatesRequired = $this->pathTemplatesRequired($effectiveValues);', $adminConfigSource);
        $this->assertStringContainsString('#(^|[\\\\/])\\.\\.([\\\\/]|$)#', $adminConfigSource);
        $this->assertStringContainsString("public const VALIDATION_INVALID_PATH_TEMPLATE = 'invalid_path_template';", $adminConfigSource);

        $adminConfigTest = self::readFile(self::projectRoot() . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'unit' . DIRECTORY_SEPARATOR . 'Service' . DIRECTORY_SEPARATOR . 'AdminConfigServiceTest.php');
        $this->assertStringContainsString('testDisabledProvisioningIgnoresStalePathFeatureFlagsForBlankTemplates', $adminConfigTest);
        $this->assertStringContainsString('testTemplateValidationRejectsTraversalAndUnsupportedPlaceholders', $adminConfigTest);
        $this->assertStringContainsString('C:\\\\immich\\\\..\\\\library\\\\{storageLabel}', $adminConfigTest);
    }

    public function testStructuredErrorCodesAndSecretRedactionGuardrailsStayCovered(): void {
        $adminSettingsController = self::readFile(self::projectRoot() . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'Controller' . DIRECTORY_SEPARATOR . 'AdminSettingsController.php');
        foreach (['admin_config_invalid', 'admin_config_save_failed', 'connection_validation_failed'] as $code) {
            $this->assertStringContainsString($code, $adminSettingsController);
        }
        $this->assertStringContainsString('fieldDetails', $adminSettingsController);
        $this->assertStringContainsString('private function redact(mixed $value): mixed', $adminSettingsController);
        $this->assertStringContainsString('private function isSecretKey(string $key): bool', $adminSettingsController);
        self::assertInitialPasswordPolicyIsSafeConfigKey($adminSettingsController, 'AdminSettingsController');

        $frontendInitialStateService = self::readFile(self::projectRoot() . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'Service' . DIRECTORY_SEPARATOR . 'FrontendInitialStateService.php');
        $this->assertStringContainsString('private function redactString(string $value): string', $frontendInitialStateService);
        self::assertInitialPasswordPolicyIsSafeConfigKey($frontendInitialStateService, 'FrontendInitialStateService');
        $this->assertStringContainsString('[?&](?:api[_-]?key|token|password|secret|authorization)=', $frontendInitialStateService);
        $this->assertStringContainsString('"(?:password|admin_api_key|apiKey|api_key|x-api-key|token|secret|authorization)"\s*:\s*"', $frontendInitialStateService);
        $this->assertStringContainsString('\b(authorization)(\s*[=:]\s*)bearer\s+', $frontendInitialStateService);
        $this->assertStringContainsString('\bbearer\s+', $frontendInitialStateService);
        $this->assertStringContainsString('(api[_-]?key|token|password|secret|authorization)(\s*[=:]\s*)', $frontendInitialStateService);

        $adminSettingsControllerTest = self::readFile(self::projectRoot() . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'unit' . DIRECTORY_SEPARATOR . 'Controller' . DIRECTORY_SEPARATOR . 'AdminSettingsControllerTest.php');
        $this->assertStringContainsString('testSetConfigReturnsStructuredValidationError', $adminSettingsControllerTest);
        $this->assertStringContainsString('testSetConfigPersistenceFailureUsesSaveFailedCodeAndRedacts', $adminSettingsControllerTest);
        $this->assertStringContainsString('testValidateConnectionFailureIsStructuredAndRedacted', $adminSettingsControllerTest);
        $this->assertStringContainsString('testGetConfigPreservesSsoOidcPasswordPolicyAndRedactsCredentialFields', $adminSettingsControllerTest);
        $this->assertStringContainsString('$this->assertSame(\'sso_oidc\', $config[\'initial_password_policy\']);', $adminSettingsControllerTest);
        $this->assertStringContainsString("assertStringNotContainsString('test-api-key-redacted'", $adminSettingsControllerTest);
        $this->assertStringContainsString("assertStringNotContainsString('test-bearer-redacted'", $adminSettingsControllerTest);

        $adminStateTest = self::readFile(self::projectRoot() . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'unit' . DIRECTORY_SEPARATOR . 'Settings' . DIRECTORY_SEPARATOR . 'AdminSettingsStateTest.php');
        $pageStateTest = self::readFile(self::projectRoot() . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'unit' . DIRECTORY_SEPARATOR . 'Controller' . DIRECTORY_SEPARATOR . 'PageControllerStateTest.php');
        $this->assertStringContainsString('testAdminInitialStatePreservesRandomPasswordPolicyAndRedactsConfigSecrets', $adminStateTest);
        $this->assertStringContainsString('$this->assertSame(\'random\', $settings[AdminConfigService::KEY_INITIAL_PASSWORD_POLICY]);', $adminStateTest);
        $this->assertStringContainsString("assertStringNotContainsString('secret-admin-key'", $adminStateTest);
        $this->assertStringContainsString("assertStringNotContainsString('test-bearer-redacted'", $adminStateTest);
        $this->assertStringContainsString("assertStringNotContainsString('json-admin-key-redacted'", $adminStateTest);
        $this->assertStringContainsString('Authorization: [redacted]', $adminStateTest);
        $this->assertStringContainsString('Bearer [redacted]', $adminStateTest);
        $this->assertStringContainsString("assertStringNotContainsString('secret-admin-key'", $pageStateTest);
    }

    public function testLocalizationGuardrailIsWiredToAuditAndRawExamples(): void {
        $localizationScript = self::readFile(self::projectRoot() . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify-localization-guardrail.mjs');
        $this->assertStringContainsString('task-4-i18n-audit.md', $localizationScript);
        $this->assertStringContainsString('knownRawEnglishExamples', $localizationScript);
        foreach ([
            'Invalid admin configuration.',
            'Failed to save admin configuration.',
            'Connection validation failed.',
            'Immich mapping status is temporarily unavailable.',
            'No active Nextcloud user context is available for Immich provisioning.',
            'No Immich mapping exists for this Nextcloud user yet. Ask an administrator to run Immich provisioning.',
            'Quota details are unavailable. Run quota sync from the admin settings for authoritative status.',
            'Quota has not been synced yet; values may be stale until the next quota sync job runs.',
            'Immich browsing is not configured for this account',
        ] as $rawExample) {
            $this->assertStringContainsString($rawExample, $localizationScript);
        }
    }

    public function testScannerCatchesForbiddenPatternInInlineControl(): void {
        $content = <<<'PHP'
<?php

use Symfony\Component\Process\Process;

shell_exec('id');
symlink('/tmp/source', '/tmp/link');

class UnsafeController {
    #[NoCSRFRequired]
    public function save(): void {
    }
}
PHP;

        $patternViolations = self::findForbiddenPatternMatchesInContent(
            'lib/Controller/UnsafeController.php',
            $content,
            array_merge(self::SHELL_PROCESS_PATTERNS, self::SYMLINK_PATTERNS)
        );
        $csrfViolations = self::findNoCsrfRequiredMutatingMethodsInContent(
            'lib/Controller/UnsafeController.php',
            $content,
            [
                'save' => [
                    [
                        'verb' => 'POST',
                        'name' => 'unsafe#save',
                        'routeLine' => 1,
                    ],
                ],
            ]
        );

        $combined = implode("\n", array_merge($patternViolations, $csrfViolations));
        $this->assertStringContainsString('shell_exec', $combined);
        $this->assertStringContainsString('Symfony\\Component\\Process', $combined);
        $this->assertStringContainsString('symlink', $combined);
        $this->assertStringContainsString('NoCSRFRequired', $combined);
    }

    /**
     * @param array<string, string> $patterns
     * @return list<string>
     */
    private function scanProductionFiles(array $patterns): array {
        $violations = [];
        foreach (self::productionPhpFiles() as $filePath) {
            $relativePath = self::relativePath($filePath);
            $content = self::readFile($filePath);
            array_push(
                $violations,
                ...self::findForbiddenPatternMatchesInContent($relativePath, $content, $patterns)
            );
        }

        sort($violations);
        return $violations;
    }

    /**
     * @return array<string, array<string, list<array{verb: string, name: string, routeLine: int}>>>
     */
    private function mutatingRoutesByController(): array {
        $routesPath = self::projectRoot() . DIRECTORY_SEPARATOR . 'appinfo' . DIRECTORY_SEPARATOR . 'routes.php';
        $routes = [];

        foreach (self::parseRouteEntries(self::readFile($routesPath)) as $route) {
            $verb = strtoupper($route['verb']);
            if (!isset(self::MUTATING_VERBS[$verb]) || !str_contains($route['name'], '#')) {
                continue;
            }

            [$controller, $action] = explode('#', $route['name'], 2);
            $routes[strtolower($controller)][$action][] = [
                'verb' => $verb,
                'name' => $route['name'],
                'routeLine' => $route['line'],
            ];
        }

        return $routes;
    }

    /**
     * @return list<array{name: string, verb: string, line: int}>
     */
    private static function parseRouteEntries(string $routesContent): array {
        preg_match_all('/\[(?<route>[^\[\]]*[\'\"]name[\'\"][^\[\]]*)\]/s', $routesContent, $routeMatches, PREG_OFFSET_CAPTURE);

        $routes = [];
        foreach ($routeMatches['route'] as $routeMatch) {
            [$routeBody, $offset] = $routeMatch;
            if (
                !preg_match('/[\'\"]name[\'\"]\s*=>\s*[\'\"]([^\'\"]+)[\'\"]/', $routeBody, $nameMatch)
                || !preg_match('/[\'\"]verb[\'\"]\s*=>\s*[\'\"]([^\'\"]+)[\'\"]/', $routeBody, $verbMatch)
            ) {
                continue;
            }

            $routes[] = [
                'name' => $nameMatch[1],
                'verb' => $verbMatch[1],
                'line' => self::lineNumberForOffset($routesContent, $offset),
            ];
        }

        return $routes;
    }

    /**
     * @param array<string, string> $patterns
     * @return list<string>
     */
    private static function findForbiddenPatternMatchesInContent(string $relativePath, string $content, array $patterns): array {
        $violations = [];

        foreach ($patterns as $label => $pattern) {
            preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as $match) {
                [$matchedText, $offset] = $match;
                $lineNumber = self::lineNumberForOffset($content, $offset);
                if (self::isAllowlistedLine(self::lineText($content, $lineNumber))) {
                    continue;
                }

                $violations[] = sprintf(
                    '%s:%d contains forbidden pattern %s (%s)',
                    $relativePath,
                    $lineNumber,
                    $label,
                    trim($matchedText)
                );
            }
        }

        sort($violations);
        return $violations;
    }

    /**
     * @param array<string, list<array{verb: string, name: string, routeLine: int}>> $mutatingActions
     * @return list<string>
     */
    private static function findNoCsrfRequiredMutatingMethodsInContent(string $relativePath, string $content, array $mutatingActions): array {
        $violations = [];

        foreach ($mutatingActions as $action => $routes) {
            $metadataLines = self::methodMetadataLines($content, $action);
            if ($metadataLines === null) {
                continue;
            }

            $noCsrfLine = self::findNoCsrfLine($metadataLines);
            if ($noCsrfLine === null) {
                continue;
            }

            foreach ($routes as $route) {
                $violations[] = sprintf(
                    '%s:%d uses NoCSRFRequired on %s route %s handled by %s(); route declared at appinfo/routes.php:%d',
                    $relativePath,
                    $noCsrfLine,
                    $route['verb'],
                    $route['name'],
                    $action,
                    $route['routeLine']
                );
            }
        }

        sort($violations);
        return $violations;
    }

    /**
     * @return list<array{line: int, text: string}>|null
     */
    private static function methodMetadataLines(string $content, string $method): ?array {
        $lines = preg_split('/\R/', $content);
        if ($lines === false) {
            throw new RuntimeException('Unable to split PHP source into lines.');
        }

        $methodPattern = '/\bpublic\s+function\s+' . preg_quote($method, '/') . '\s*\(/';
        foreach ($lines as $index => $line) {
            if (!preg_match($methodPattern, $line)) {
                continue;
            }

            $metadataLines = [
                [
                    'line' => $index + 1,
                    'text' => $line,
                ],
            ];

            for ($previous = $index - 1; $previous >= 0; $previous--) {
                $previousLine = $lines[$previous];
                $trimmed = trim($previousLine);

                if (
                    $trimmed === ''
                    || str_starts_with($trimmed, '#[')
                    || str_starts_with($trimmed, '/**')
                    || str_starts_with($trimmed, '*')
                    || str_starts_with($trimmed, '*/')
                    || str_starts_with($trimmed, '//')
                ) {
                    array_unshift($metadataLines, [
                        'line' => $previous + 1,
                        'text' => $previousLine,
                    ]);
                    continue;
                }

                break;
            }

            return $metadataLines;
        }

        return null;
    }

    /**
     * @param list<array{line: int, text: string}> $metadataLines
     */
    private static function findNoCsrfLine(array $metadataLines): ?int {
        foreach ($metadataLines as $metadataLine) {
            if (
                str_contains($metadataLine['text'], 'NoCSRFRequired')
                && (str_contains($metadataLine['text'], '#[') || str_contains($metadataLine['text'], '@NoCSRFRequired'))
            ) {
                return $metadataLine['line'];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function productionPhpFiles(): array {
        $files = [];
        foreach (['lib', 'appinfo'] as $directory) {
            $root = self::projectRoot() . DIRECTORY_SEPARATOR . $directory;
            if (!is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
                    continue;
                }

                $filePath = $fileInfo->getPathname();
                if (strtolower($fileInfo->getExtension()) !== 'php' || self::isAllowlistedPath($filePath)) {
                    continue;
                }

                $files[] = $filePath;
            }
        }

        sort($files);
        return $files;
    }

    /**
     * @return list<string>
     */
    private static function controllerFiles(): array {
        return array_values(array_filter(
            self::productionPhpFiles(),
            static fn(string $filePath): bool => str_contains(strtolower(self::normalizedPath($filePath)), '/lib/controller/')
        ));
    }

    private static function controllerKeyFromPath(string $filePath): string {
        $className = basename($filePath, '.php');
        $controllerName = preg_replace('/Controller$/', '', $className);
        if ($controllerName === null) {
            throw new RuntimeException('Unable to derive controller name from ' . $filePath);
        }

        return strtolower($controllerName);
    }

    private static function assertInitialPasswordPolicyIsSafeConfigKey(string $source, string $label): void {
        $safeKeysIndex = strpos($source, 'private const SAFE_CONFIG_KEYS = [');
        $policyKeyIndex = strpos($source, 'AdminConfigService::KEY_INITIAL_PASSWORD_POLICY', $safeKeysIndex === false ? 0 : $safeKeysIndex);
        $secretKeysIndex = strpos($source, 'private const SECRET_CONFIG_KEYS = [');
        if ($safeKeysIndex === false || $policyKeyIndex === false || $secretKeysIndex === false) {
            self::fail($label . ' must classify initial_password_policy in SAFE_CONFIG_KEYS before SECRET_CONFIG_KEYS.');
        }
        self::assertLessThan($policyKeyIndex, $safeKeysIndex, $label . ' SAFE_CONFIG_KEYS must be defined before initial_password_policy.');
        self::assertLessThan($secretKeysIndex, $policyKeyIndex, $label . ' initial_password_policy must be in SAFE_CONFIG_KEYS, not SECRET_CONFIG_KEYS.');

        $isSecretKeyIndex = strpos($source, 'private function isSecretKey');
        if ($isSecretKeyIndex === false) {
            self::fail($label . ' must define isSecretKey.');
        }
        $safeCheckIndex = strpos($source, 'in_array($normalisedKey, self::SAFE_CONFIG_KEYS, true)', $isSecretKeyIndex);
        $secretCheckIndex = strpos($source, 'in_array($normalisedKey, self::SECRET_CONFIG_KEYS, true)', $isSecretKeyIndex);
        if ($safeCheckIndex === false || $secretCheckIndex === false) {
            self::fail($label . ' must check SAFE_CONFIG_KEYS and SECRET_CONFIG_KEYS in isSecretKey.');
        }
        self::assertLessThan($secretCheckIndex, $safeCheckIndex, $label . ' must check SAFE_CONFIG_KEYS before SECRET_CONFIG_KEYS.');
    }

    private static function projectRoot(): string {
        return dirname(__DIR__, 2);
    }

    private static function readFile(string $filePath): string {
        $content = file_get_contents($filePath);
        if (!is_string($content)) {
            throw new RuntimeException('Unable to read ' . $filePath);
        }

        return $content;
    }

    private static function isAllowlistedPath(string $filePath): bool {
        $normalized = self::normalizedPath($filePath);

        return str_contains($normalized, 'test')
            || str_contains($normalized, 'Test')
            || str_contains($normalized, 'evidence')
            || str_contains($normalized, '/.omo/evidence/');
    }

    private static function isAllowlistedLine(string $line): bool {
        foreach (self::ALLOWLIST_MARKERS as $marker) {
            if (str_contains($line, $marker)) {
                return true;
            }
        }

        return false;
    }

    private static function lineNumberForOffset(string $content, int $offset): int {
        return substr_count(substr($content, 0, $offset), "\n") + 1;
    }

    private static function lineText(string $content, int $lineNumber): string {
        $lines = preg_split('/\R/', $content);
        if ($lines === false) {
            throw new RuntimeException('Unable to split PHP source into lines.');
        }

        return $lines[$lineNumber - 1] ?? '';
    }

    private static function relativePath(string $filePath): string {
        $root = self::normalizedPath(self::projectRoot()) . '/';
        $normalizedPath = self::normalizedPath($filePath);

        if (str_starts_with($normalizedPath, $root)) {
            return substr($normalizedPath, strlen($root));
        }

        return $normalizedPath;
    }

    private static function normalizedPath(string $filePath): string {
        return str_replace('\\', '/', $filePath);
    }
}
