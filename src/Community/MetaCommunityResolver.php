<?php

declare(strict_types=1);

namespace FreifunkKarte\Community;

/**
 * The API files only name the meta-community a community belongs to ("Freifunk Franken"), they
 * do not say where it lives. The link is derived from the members, in this order:
 *
 *  1. the override file (a url, or null to suppress the link)
 *  2. a member that is the meta-community itself (same name or key): its own url
 *  3. a host that most members (at least 2, at least 60%) publish: https://host/
 *  4. no link - the frontend then shows the name as plain text
 *
 * A meta-community with a single member is that community; it only gets a link by override.
 */
final class MetaCommunityResolver
{
    private const SHARED_HOST_SHARE = 0.6;

    /** hosts that many unrelated communities share; never a meta-community's home */
    private const GENERIC_HOSTS = ['freifunk.net'];

    /** words that do not tell communities apart when comparing names */
    private const NOISE_WORDS = ['freifunk', 'e', 'v', 'ev', 'verein'];

    /**
     * @param array<string, string|null> $overrides meta-community name => url, or null to suppress
     */
    public function __construct(private readonly array $overrides = [])
    {
    }

    /**
     * Reads the override file; a missing file means no overrides.
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            return new self();
        }

        $data = json_decode((string) file_get_contents($path), true);
        $overrides = [];

        foreach (is_array($data) ? $data : [] as $name => $entry) {
            if (!is_array($entry) || !array_key_exists('url', $entry)) {
                continue;
            }

            if ($entry['url'] === null || (is_string($entry['url']) && $entry['url'] !== '')) {
                $overrides[(string) $name] = $entry['url'];
            }
        }

        return new self($overrides);
    }

    /**
     * @param list<CommunityInfo> $communities
     * @return array<string, MetaLink> by meta-community name, in order of first appearance
     */
    public function resolve(array $communities): array
    {
        $members = [];

        foreach ($communities as $community) {
            if ($community->meta !== null && $community->meta !== '') {
                $members[$community->meta][] = $community;
            }
        }

        $links = [];

        foreach ($members as $name => $group) {
            $links[$name] = $this->link((string) $name, $group);
        }

        return $links;
    }

    /**
     * @param list<CommunityInfo> $members
     */
    private function link(string $name, array $members): MetaLink
    {
        $count = count($members);

        if (array_key_exists($name, $this->overrides)) {
            $url = $this->overrides[$name];

            return new MetaLink($name, $url, $url === null ? MetaLink::SUPPRESSED : MetaLink::OVERRIDE, $count);
        }

        if ($count === 1) {
            return new MetaLink($name, null, MetaLink::SINGLE, $count);
        }

        foreach ($members as $member) {
            if ($member->url !== '' && $this->sameName($member, $name)) {
                return new MetaLink($name, $member->url, MetaLink::MEMBER, $count);
            }
        }

        $shared = $this->sharedHost($members);

        return new MetaLink($name, $shared, $shared !== null ? MetaLink::SHARED_HOST : MetaLink::NONE, $count);
    }

    private function sameName(CommunityInfo $member, string $metaName): bool
    {
        $wanted = $this->normalise($metaName);

        return $wanted !== '' && ($this->normalise($member->name) === $wanted || $this->normalise($member->key) === $wanted);
    }

    private function normalise(string $name): string
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode('', array_diff($tokens, self::NOISE_WORDS));
    }

    /**
     * @param list<CommunityInfo> $members
     */
    private function sharedHost(array $members): ?string
    {
        $count = [];
        $firstUrl = [];

        foreach ($members as $member) {
            $host = $this->host($member->url);

            if ($host === '' || in_array($host, self::GENERIC_HOSTS, true)) {
                continue;
            }

            $count[$host] = ($count[$host] ?? 0) + 1;
            $firstUrl[$host] ??= $member->url;
        }

        if ($count === []) {
            return null;
        }

        arsort($count);
        $host = (string) array_key_first($count);

        if ($count[$host] < 2 || $count[$host] < self::SHARED_HOST_SHARE * count($members)) {
            return null;
        }

        $scheme = parse_url($firstUrl[$host], PHP_URL_SCHEME);

        return (is_string($scheme) ? $scheme : 'https') . '://' . $host . '/';
    }

    private function host(string $url): string
    {
        $parts = parse_url(str_contains($url, '//') ? $url : '//' . $url);
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';

        return (string) preg_replace('/^www\./', '', mb_strtolower($host));
    }
}
