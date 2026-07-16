<?php
/**
 * Laika Framework
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika PHP Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Laika\Route\Response;

use DOMDocument;
// use Laika\Service\{CSRF, Response};
use Laika\Service\Response;
use Laika\Service\CSRF;

final class Html
{
    /** @var string CSRF Token */
    private static ?string $csrf = null;

    /**
     * Render Html
     * @param string $str
     * @return void
     */
    public static function render(string $str): void
    {
        // Check $str is Not Empty
        // if (empty($str)) return;
        $dom = new DOMDocument();

        // Suppress warnings caused by invalid HTML
        libxml_use_internal_errors(true);

        $dom->loadHTML($str, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        libxml_clear_errors();

        $forms = $dom->getElementsByTagName('form');

        // Check Empty Form, Send Response
        if ($forms->length == 0) {
            Response::html($str)->send();
            return;
        }

        foreach ($forms as $form) {
            $hasCsrf = false;

            foreach ($form->getElementsByTagName('input') as $input) {
                // Check CSRF Input Field Exists
                if (
                    strtolower($input->getAttribute('type')) == 'hidden' &&
                    $input->getAttribute('name') == '_csrf'
                ) {
                    $hasCsrf = true;
                    break;
                }
                // Check CSRF Input Type is Hidden
                if (
                    $input->getAttribute('name') == '_csrf' &&
                    strtolower($input->getAttribute('type'))!= 'hidden'
                ) {
                    Response::json(
                        [
                            "status"        =>  "failed",
                            "message"       =>  "CSRF Input Type Shoud Be [hidden]",
                            "event_time"    =>  Date::toIso8601()
                        ],
                        415)
                    ->send();
                    return;
                }
            }

            // Add CSRF Input if Does Not Exists
            if (!$hasCsrf) {
                $csrf = $dom->createElement('input');

                $csrf->setAttribute('type', 'hidden');
                $csrf->setAttribute('name', '_csrf');
                $csrf->setAttribute('value', htmlspecialchars(CSRF::generate()));

                $firstChild = $form->firstChild;
                $comment = $dom->createComment(' CSRF Field Added By App ');
                $form->insertBefore($dom->createTextNode("\n"), $firstChild);
                $form->insertBefore($comment, $firstChild);
                $form->insertBefore($dom->createTextNode("\n"), $firstChild);
                $form->insertBefore($csrf, $firstChild);
                $form->insertBefore($dom->createTextNode("\n"), $firstChild);
            }
        }
        Response::html($dom->saveHTML())->send();
    }
}
