<?php

declare(strict_types=1);

namespace App\Services\Gmail;

/**
 * Builds Google Workspace Add-on CardService (Card V1) JSON. In the alternate runtime
 * (MASTER-PLAN §6.1) Google POSTs an event to our backend and we respond with this JSON
 * — there is no Apps Script. CardService is a fixed widget catalog (no arbitrary HTML),
 * so this helper emits only supported widgets.
 *
 * @see https://developers.google.com/workspace/add-ons/concepts/cards
 */
final class CardService
{
    /**
     * Wrap cards into a render action response Google expects for a contextual trigger.
     *
     * @param  array<int, array<string, mixed>>  $cards
     * @return array<string, mixed>
     */
    public static function renderCards(array $cards): array
    {
        return [
            'renderActions' => [
                'action' => [
                    'navigations' => [
                        ['pushCard' => count($cards) === 1 ? $cards[0] : ['sections' => array_merge(...array_map(
                            static fn (array $c) => $c['sections'] ?? [],
                            $cards,
                        ))]],
                    ],
                ],
            ],
        ];
    }

    /**
     * A single card with a header and sections.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<string, mixed>
     */
    public static function card(string $title, ?string $subtitle, array $sections): array
    {
        $card = [
            'header' => array_filter([
                'title' => $title,
                'subtitle' => $subtitle,
            ], static fn ($v) => $v !== null),
            'sections' => $sections,
        ];

        return $card;
    }

    /**
     * @param  array<int, array<string, mixed>>  $widgets
     * @return array<string, mixed>
     */
    public static function section(array $widgets, ?string $header = null): array
    {
        return array_filter([
            'header' => $header,
            'widgets' => $widgets,
        ], static fn ($v) => $v !== null);
    }

    /** A decoratedText (key/value) widget. */
    public static function text(string $topLabel, string $content): array
    {
        return ['decoratedText' => ['topLabel' => $topLabel, 'text' => $content, 'wrapText' => true]];
    }

    public static function textParagraph(string $text): array
    {
        return ['textParagraph' => ['text' => $text]];
    }

    /**
     * A button that POSTs back to $functionUrl with $parameters as the action payload.
     *
     * @param  array<string, string>  $parameters
     */
    public static function actionButton(string $label, string $functionUrl, array $parameters = []): array
    {
        return [
            'buttonList' => [
                'buttons' => [[
                    'text' => $label,
                    'onClick' => [
                        'action' => [
                            'function' => $functionUrl,
                            'parameters' => array_map(
                                static fn (string $k, string $v) => ['key' => $k, 'value' => $v],
                                array_keys($parameters),
                                array_values($parameters),
                            ),
                        ],
                    ],
                ]],
            ],
        ];
    }

    /** A transient notification (toast) render action, e.g. after logging. */
    public static function notification(string $text): array
    {
        return [
            'renderActions' => [
                'action' => [
                    'notification' => ['text' => $text],
                ],
            ],
        ];
    }
}
