<?php

namespace Tests\Feature\Core;

use Modules\Core\Enums\ExternalDecision;
use Modules\Core\Models\ExternalApproval;
use Modules\Core\Support\ExternalApprovableDocuments;
use Tests\ErpTestCase;

/**
 * The external-approvable list exists twice: once in PHP, where it decides
 * which documents the API issues links for, and once in the SPA, where it
 * decides which detail screens show the Persetujuan Eksternal card. There is
 * no build step to share it (AttachmentRegistryTest precedent), so this reads
 * both sides and fails when they diverge.
 *
 * Divergence is silent in both directions: a slug only in the SPA renders a
 * card whose every request 422s, and a slug only in PHP is a document whose
 * links can be issued by nobody, because no screen carries the button.
 */
class ExternalApprovalRegistryTest extends ErpTestCase
{
    public function test_the_php_and_javascript_registries_list_the_same_documents(): void
    {
        $php = ExternalApprovableDocuments::slugs();
        sort($php);

        $js = $this->javascriptSlugs();
        sort($js);

        $this->assertSame(
            $php,
            $js,
            "public/app/js/views/external.js EXTERNAL_APPROVABLE has drifted from Modules\\Core\\Support\\ExternalApprovableDocuments.\n"
            .'Only in PHP: '.implode(', ', array_diff($php, $js))."\n"
            .'Only in JS:  '.implode(', ', array_diff($js, $php)),
        );
    }

    /**
     * Every external-approvable slug must be rendered by SOME screen, or the
     * issue/revoke/record buttons exist nowhere. Today all three are generic
     * RESOURCES; the custom-view branches mirror AttachmentRegistryTest so a
     * future member on a hand-written detail keeps passing for the right
     * reason instead of forcing a schema entry it does not have.
     */
    public function test_every_external_approvable_slug_is_rendered_by_some_screen(): void
    {
        $schema = (string) file_get_contents(public_path('app/js/schema.js'));

        $custom = '';
        foreach (glob(public_path('app/js/views/*.js')) as $view) {
            $custom .= (string) file_get_contents($view);
        }

        foreach (ExternalApprovableDocuments::slugs() as $slug) {
            $rendered = str_contains($schema, "  '{$slug}': {")
                || str_contains($custom, "externalApprovalsCard('{$slug}'")
                || str_contains($custom, "RESOURCES['{$slug}'] = {");

            $this->assertTrue($rendered, sprintf(
                'External-approvable slug [%s] is neither a RESOURCES key nor passed to externalApprovalsCard() '
                .'by a custom view, so nothing can ever show its external approvals.',
                $slug,
            ));
        }
    }

    /**
     * The card is mounted ONCE, in the generic detail screen — the same wiring
     * as attachmentsCard. If this line leaves detail.js, every registry member
     * loses its card at once and the two tests above keep passing; this one
     * does not.
     */
    public function test_the_generic_detail_screen_mounts_the_card(): void
    {
        $detail = (string) file_get_contents(public_path('app/js/views/detail.js'));

        $this->assertStringContainsString(
            'externalApprovalsCard(key, record.id, def.module)',
            $detail,
            'detail.js no longer mounts externalApprovalsCard for generic detail screens.',
        );
    }

    /**
     * The dialogs speak the server's vocabulary: party keys/labels from
     * ExternalApproval::PARTIES, decision values/labels from ExternalDecision.
     * A drifted key is a 422 on every submit; a drifted label is a chip that
     * contradicts the printed sheet.
     */
    public function test_party_and_decision_vocabulary_match_the_php_side(): void
    {
        $this->assertSame(
            ExternalApproval::PARTIES,
            $this->javascriptObject('PARTY_LABELS'),
            'PARTY_LABELS in external.js has drifted from ExternalApproval::PARTIES.',
        );

        $decisions = [];
        foreach (ExternalDecision::cases() as $case) {
            $decisions[$case->value] = $case->label();
        }

        $this->assertSame(
            $decisions,
            $this->javascriptObject('DECISION_LABELS'),
            'DECISION_LABELS in external.js has drifted from ExternalDecision.',
        );
    }

    /** @return list<string> */
    private function javascriptSlugs(): array
    {
        $source = $this->externalJs();

        $this->assertSame(
            1,
            preg_match('/export const EXTERNAL_APPROVABLE = new Set\(\[(.*?)\]\);/s', $source, $matches),
            'EXTERNAL_APPROVABLE could not be found in external.js; this test can no longer check anything.',
        );

        preg_match_all("/'([a-z-]+\/[a-z-]+)'/", $matches[1], $slugs);

        return $slugs[1];
    }

    /** @return array<string, string> */
    private function javascriptObject(string $name): array
    {
        $source = $this->externalJs();

        $this->assertSame(
            1,
            preg_match('/const '.$name.' = \{(.*?)\};/s', $source, $matches),
            "{$name} could not be found in external.js; this test can no longer check anything.",
        );

        preg_match_all("/([a-z_]+): '([^']+)'/", $matches[1], $pairs, PREG_SET_ORDER);

        $object = [];
        foreach ($pairs as $pair) {
            $object[$pair[1]] = $pair[2];
        }

        return $object;
    }

    private function externalJs(): string
    {
        return (string) file_get_contents(public_path('app/js/views/external.js'));
    }
}
