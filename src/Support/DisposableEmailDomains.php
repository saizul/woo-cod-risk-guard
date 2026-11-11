<?php
/**
 * Disposable email domain detection.
 *
 * @package Saizul\CodRiskGuard
 */

declare(strict_types=1);

namespace Saizul\CodRiskGuard\Support;

/**
 * Checks email addresses against a list of throw-away mailbox providers.
 *
 * The bundled list is intentionally small and conservative; merchants can
 * extend it from the settings screen or with a filter.
 */
final class DisposableEmailDomains
{
    /**
     * Bundled disposable domains.
     *
     * @var string[]
     */
    private const BUNDLED = [
        '10minutemail.com', '10minutemail.net', '20minutemail.com', '33mail.com',
        'anonaddy.me', 'burnermail.io', 'discard.email', 'discardmail.com',
        'dispostable.com', 'dropmail.me', 'emailondeck.com', 'fakeinbox.com',
        'fakemail.net', 'getairmail.com', 'getnada.com', 'guerrillamail.biz',
        'guerrillamail.com', 'guerrillamail.de', 'guerrillamail.info',
        'guerrillamail.net', 'guerrillamail.org', 'harakirimail.com',
        'inboxbear.com', 'inboxkitten.com', 'jetable.org', 'mail-temp.com',
        'mailcatch.com', 'maildrop.cc', 'mailinator.com', 'mailinator.net',
        'mailnesia.com', 'mailsac.com', 'mailtemp.info', 'minuteinbox.com',
        'mintemail.com', 'moakt.com', 'mohmal.com', 'mytemp.email',
        'nada.email', 'sharklasers.com', 'spam4.me', 'spambog.com',
        'spamgourmet.com', 'temp-mail.io', 'temp-mail.org', 'tempail.com',
        'tempinbox.com', 'tempmail.com', 'tempmail.net', 'tempmailo.com',
        'tempr.email', 'throwawaymail.com', 'tmpmail.net', 'tmpmail.org',
        'trashmail.com', 'trashmail.de', 'trashmail.net', 'yopmail.com',
        'yopmail.fr', 'yopmail.net',
    ];

    /**
     * Lookup table of domains.
     *
     * @var array<string, bool>
     */
    private array $domains = [];

    /**
     * @param string[] $extra          Additional domains to treat as disposable.
     * @param bool     $includeBundled Whether to include the bundled list.
     */
    public function __construct(array $extra = [], bool $includeBundled = true)
    {
        if ($includeBundled) {
            foreach (self::BUNDLED as $domain) {
                $this->domains[$domain] = true;
            }
        }

        foreach ($extra as $domain) {
            $this->add((string) $domain);
        }
    }

    /**
     * Add a domain to the list.
     */
    public function add(string $domain): void
    {
        $domain = self::cleanDomain($domain);
        if ('' !== $domain) {
            $this->domains[$domain] = true;
        }
    }

    /**
     * Whether a bare domain (or one of its parent domains) is disposable.
     */
    public function isDisposableDomain(string $domain): bool
    {
        $domain = self::cleanDomain($domain);
        if ('' === $domain) {
            return false;
        }

        $labels = explode('.', $domain);
        while (count($labels) >= 2) {
            if (isset($this->domains[implode('.', $labels)])) {
                return true;
            }
            array_shift($labels);
        }

        return false;
    }

    /**
     * Whether the email address uses a disposable domain.
     */
    public function isDisposable(string $email): bool
    {
        return $this->isDisposableDomain(EmailNormalizer::domain($email));
    }

    /**
     * Number of known domains.
     */
    public function count(): int
    {
        return count($this->domains);
    }

    /**
     * Normalise a user supplied domain ("@Example.com " becomes "example.com").
     */
    public static function cleanDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = ltrim($domain, '@');
        $domain = trim($domain, ". \t\n\r\0\x0B");

        if ('' === $domain || 1 !== preg_match('/^[a-z0-9.-]+\.[a-z0-9-]{2,}$/', $domain)) {
            return '';
        }

        return $domain;
    }
}
