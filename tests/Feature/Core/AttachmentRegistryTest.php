<?php

namespace Tests\Feature\Core;

use Modules\Core\Support\AttachableDocuments;
use Tests\ErpTestCase;

/**
 * The attachable-document list exists twice: once in PHP, where it decides what
 * the API accepts, and once in the SPA, where it decides which screens show the
 * card. There is no build step to share it, so this reads both and fails when
 * they diverge.
 *
 * Divergence is silent in both directions and neither is obvious to whoever
 * caused it: a slug only in the SPA renders a card whose every request 422s,
 * and a slug only in PHP is a document that quietly cannot hold files even
 * though the backend would accept them.
 */
class AttachmentRegistryTest extends ErpTestCase
{
    public function test_the_php_and_javascript_registries_list_the_same_documents(): void
    {
        $php = AttachableDocuments::slugs();
        sort($php);

        $js = $this->javascriptSlugs();
        sort($js);

        $this->assertSame(
            $php,
            $js,
            "public/app/js/views/attachments.js ATTACHABLE has drifted from Modules\\Core\\Support\\AttachableDocuments.\n"
            .'Only in PHP: '.implode(', ', array_diff($php, $js))."\n"
            .'Only in JS:  '.implode(', ', array_diff($js, $php)),
        );
    }

    /**
     * Every attachable slug must be rendered by SOME screen, or its card exists
     * nowhere. Most are generic RESOURCES; a few belong to hand-written detail
     * views, which pass the slug to attachmentsCard() themselves.
     */
    public function test_every_attachable_slug_is_rendered_by_some_screen(): void
    {
        $schema = (string) file_get_contents(public_path('app/js/schema.js'));

        $custom = '';
        foreach (glob(public_path('app/js/views/*.js')) as $view) {
            $custom .= (string) file_get_contents($view);
        }

        foreach (AttachableDocuments::slugs() as $slug) {
            $rendered = str_contains($schema, "  '{$slug}': {")
                || str_contains($custom, "attachmentsCard('{$slug}'")
                // kaskecil.js registers its three resources at import time
                // (RESOURCES['slug'] = {...}); the generic detail renders their
                // attachment card exactly as it does for a schema.js key.
                || str_contains($custom, "RESOURCES['{$slug}'] = {");

            $this->assertTrue($rendered, sprintf(
                'Attachable slug [%s] is neither a RESOURCES key nor passed to attachmentsCard() by a custom '
                .'view, so nothing can ever show its attachments.',
                $slug,
            ));
        }
    }

    /** @return list<string> */
    private function javascriptSlugs(): array
    {
        $source = (string) file_get_contents(public_path('app/js/views/attachments.js'));

        $this->assertSame(
            1,
            preg_match('/export const ATTACHABLE = new Set\(\[(.*?)\]\);/s', $source, $matches),
            'ATTACHABLE could not be found in attachments.js; this test can no longer check anything.',
        );

        preg_match_all("/'([a-z-]+\/[a-z-]+)'/", $matches[1], $slugs);

        return $slugs[1];
    }
}
