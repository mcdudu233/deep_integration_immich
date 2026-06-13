<?php

/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\IntegrationImmich\Http;

use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\RedirectResponse;

class SharedCookieRedirectResponse extends RedirectResponse implements ICallbackResponse {
    /**
     * @param string[] $setCookies
     */
    public function __construct(string $redirectUrl, private array $setCookies) {
        parent::__construct($redirectUrl);
    }

    public function callback(IOutput $output): void {
        foreach ($this->setCookies as $setCookie) {
            header('Set-Cookie: ' . $setCookie, false);
        }
    }

    /**
     * @return string[]
     */
    public function getSetCookies(): array {
        return $this->setCookies;
    }
}
