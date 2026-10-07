<?php

namespace App\Services\Checkout;

use App\Enums\LegalDocumentType;
use App\Models\Market;
use App\Models\MarketConfiguration;

/**
 * The legal documents a customer accepts when confirming a checkout (Module 29): which ones, in which version, with
 * which effective date and in which approval state. Versions come from config/legal.php; whether a document's text
 * has final approval comes from the market (market_configurations.legal.documents). Until the owner supplies
 * approved text every document reads DRAFT_PENDING_APPROVAL — recorded as such, never dressed up as final.
 */
final class LegalDocuments
{
    /**
     * @return list<array{type: string, version: string, effective_date: string|null, status: string, slug: string}>
     */
    public function requiredFor(Market $market): array
    {
        $statuses = (array) (MarketConfiguration::query()->where('market_id', $market->getKey())->value('legal')['documents'] ?? []);
        $documents = [];
        foreach ((array) config('legal.checkout_required') as $key) {
            $type = LegalDocumentType::tryFrom(strtoupper((string) $key));
            $definition = config('legal.documents.'.$key);
            if ($type === null || ! is_array($definition)) {
                continue;
            }
            $documents[] = [
                'type' => $type->value,
                'version' => (string) $definition['version'],
                'effective_date' => $definition['effective_date'] === null ? null : (string) $definition['effective_date'],
                'status' => (string) ($statuses[$key] ?? config('legal.default_status')),
                // Where the text lives is the client's business (web: /terms, Android: /legal/terms): the slug names it.
                'slug' => (string) $definition['slug'],
            ];
        }

        return $documents;
    }

    /**
     * True only when every required document of the market has final approval.
     *
     * @param  list<array{status: string}>  $documents
     */
    public function finalApproval(array $documents): bool
    {
        return $documents !== [] && array_filter($documents, fn (array $d): bool => $d['status'] !== config('legal.approved_status')) === [];
    }

    /**
     * The required documents that the accepted list does not cover at their CURRENT version.
     *
     * @param  list<array{type: string, version: string}>  $required
     * @param  list<array{type?: mixed, version?: mixed}>  $accepted
     * @return list<array{type: string, version: string}>
     */
    public function missing(array $required, array $accepted): array
    {
        $given = [];
        foreach ($accepted as $entry) {
            if (is_string($entry['type'] ?? null) && is_string($entry['version'] ?? null)) {
                $given[strtoupper($entry['type'])] = $entry['version'];
            }
        }

        return array_values(array_map(
            fn (array $d): array => ['type' => $d['type'], 'version' => $d['version']],
            array_filter($required, fn (array $d): bool => ($given[$d['type']] ?? null) !== $d['version']),
        ));
    }
}
