<?php

declare(strict_types=1);

namespace TrackAnyDevice\Tad101\Http\Controllers;

use Illuminate\Routing\Controller;
use TrackAnyDevice\Tad101\Tad101Driver;
use TrackAnyDevice\Core\Models\Sensor;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public-facing documentation pages for the TAD101 protocol.
 *
 * Every method returns an Inertia React page under `docs/tad101/*`. The
 * shared layout (sidebar + content) is rendered by `docs/tad101/DocsLayout`
 * imported by each page; the controller just hands over typed props.
 *
 * TAD101_DOC_UPDATE: keep the section list here in sync with the sidebar in
 * resources/js/pages/docs/tad101/DocsLayout.tsx.
 */
class TadDocsController extends Controller
{
    private const VERSION = '1.0.0';

    private const LAST_UPDATED = '2026-05-19';

    public function overview(): Response
    {
        return Inertia::render('web/docs/tad101/overview', $this->base('overview', 'Overview'));
    }

    public function architecture(): Response
    {
        return Inertia::render('web/docs/tad101/architecture', $this->base('architecture', 'Architecture'));
    }

    public function envelope(): Response
    {
        return Inertia::render('web/docs/tad101/envelope', $this->base('envelope', 'Message Envelope'));
    }

    public function android(): Response
    {
        return Inertia::render('web/docs/tad101/android', $this->base('android', 'Android Integration'));
    }

    public function ios(): Response
    {
        return Inertia::render('web/docs/tad101/ios', $this->base('ios', 'iOS Integration'));
    }

    public function arduino(): Response
    {
        return Inertia::render('web/docs/tad101/arduino', $this->base('arduino', 'Arduino Guide'));
    }

    public function raspberryPi(): Response
    {
        return Inertia::render('web/docs/tad101/raspberry-pi', $this->base('raspberry-pi', 'Raspberry Pi Guide'));
    }

    public function sensors(): Response
    {
        $rows = Sensor::query()
            ->orderBy('sort_order')
            ->get(['slug', 'name', 'label', 'data_type', 'unit', 'description'])
            ->map(fn (Sensor $s) => [
                'slug' => $s->slug,
                'name' => $s->name,
                'label' => $s->label,
                'data_type' => $s->data_type,
                'unit' => $s->unit,
                'description' => $s->description,
            ])
            ->all();

        return Inertia::render('web/docs/tad101/sensors', $this->base('sensors', 'Sensor Registry', [
            'sensors' => $rows,
        ]));
    }

    public function commands(): Response
    {
        $commands = collect(app(Tad101Driver::class)->addOnCommands())
            ->map(fn ($cmd) => $cmd->toArray())
            ->groupBy('category')
            ->map(fn ($group) => $group->values()->all())
            ->toArray();

        return Inertia::render('web/docs/tad101/commands', $this->base('commands', 'Command Registry', [
            'commands' => $commands,
        ]));
    }

    public function presentYourIdea(): Response
    {
        return Inertia::render('web/docs/tad101/present-your-idea', $this->base('present-your-idea', 'Present Your Idea'));
    }

    public function changelog(): Response
    {
        return Inertia::render('web/docs/tad101/changelog', $this->base('changelog', 'Changelog', [
            'entries' => [
                [
                    'date' => '2026-05-19',
                    'version' => '1.0.0',
                    'author' => 'Ahmad + Claude',
                    'change' => 'Initial specification & driver implementation.',
                ],
            ],
        ]));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function base(string $active, string $pageTitle, array $extra = []): array
    {
        return array_merge([
            'version' => self::VERSION,
            'lastUpdated' => self::LAST_UPDATED,
            'active' => $active,
            'pageTitle' => $pageTitle,
        ], $extra);
    }
}
