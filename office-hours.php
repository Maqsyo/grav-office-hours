<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;

/**
 * Class OfficeHoursPlugin
 * @package Grav\Plugin
 */
class OfficeHoursPlugin extends Plugin
{
    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [
                ['onPluginsInitialized', 0]
            ]
        ];
    }

    /**
     * Composer autoload
     *
     * @return ClassLoader
     */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized(): void
    {
        // Don't proceed if we are in the admin plugin
        if ($this->isAdmin()) { return; }

        // Enable the main events we are interested in
        $this->enable([
            'onTwigInitialized' => ['onTwigInitialized', 0]
        ]);
    }

    public function onTwigInitialized()
    {
        $this->grav['twig']->twig()->addFunction(
            // what is "this" in the parameters?
            new \Twig_SimpleFunction('getOfficeHoursData', [$this, 'getOfficeHoursData'])
        );
    }

    public function getOfficeHoursData()
    {
        $data = [
            'openinghours' => [],
            'specialOpenings' => [],
            'closedDays' => []
        ];

        $trimTime = !!$this->config->get('plugins.office-hours.trimTime');

        $openinghours = $this->config->get('plugins.office-hours.openinghours') ?? [];
        $specialOpenings = $this->config->get('plugins.office-hours.specialOpenings') ?? [];
        $closedDays = $this->config->get('plugins.office-hours.closed') ?? [];

        foreach ($openinghours as $day => $dayConfig)
        {
            if ($dayConfig['hidden']) { continue; } // remove hidden entries

            $languageKey = strtoupper($day);

            $data['openinghours'][] = [
                'languageKey' => $languageKey,
                'dayName' => $this->grav['language']->translate([
                    'PLUGIN_OFFICE_HOURS.DAYS.' . $languageKey
                ]),
                'entries' => $this->cleanUpDayEntries($dayConfig['entries'], $trimTime)
            ];
        }

        $now = new \DateTime();
        $today = new \DateTime($now->format('Y-m-d'));

        foreach ($specialOpenings as $dayConfig)
        {
            $dayDate = new \DateTime($dayConfig['date']);
            if ($today > $dayDate) { return; }

            $languageKey = strtoupper($dayDate->format('l'));

            $data['specialOpenings'][] = [
                'date' => $dayDate,
                'languageKey' => $languageKey,
                'dayName' => $this->grav['language']->translate([
                    'PLUGIN_OFFICE_HOURS.DAYS.' . $languageKey
                ]),
                'entries' => $this->cleanUpDayEntries($dayConfig['entries'], $trimTime)
            ];
        }

        return $data;
    }

    private function timeToMinutes(string $time)
    {
        $timeArr = explode(':', $time);
        return ($timeArr[0] * 60) + $timeArr[1];
    }

    private function cleanUpDayEntries($entries, $trimTime)
    {
        $cacheArray = [];

        foreach ($entries as $entry)
        {
            $startString = $entry['start'];
            $startTime = $this->timeToMinutes($startString);
            $endString = $entry['end'];
            $endTime = $this->timeToMinutes($endString);

            if ($startTime === $endTime) { continue; } // ignore - invalid

            if ($startTime > $endTime) // swap
            {
                $tmp = $startString;
                $startString = $endString;
                $endString = $tmp;

                $tmp = $startTime;
                $startTime = $endTime;
                $endTime = $tmp;
            }

            $overlapping = false;

            foreach ($cacheArray as $timeEntry)
            {
                $eStart = $timeEntry[0];
                $eEnd = $timeEntry[1];

                if (
                    ($startTime <= $eStart && $endTime >= $eStart) ||
                    ($startTime <= $eEnd && $endTime >= $eEnd)
                )
                {
                    $overlapping = true;
                    break;
                }
            }

            if ($overlapping) { continue; } // ignore - invalid

            // trim last 3 characters if trimming is enabled and minutes == 0
            if ($trimTime)
            {
                if (substr($startString, -2) === '00')
                {
                    $startString = substr($startString, 0, 2);
                }

                if (substr($endString, -2) === '00')
                {
                    $endString = substr($endString, 0, 2);
                }
            }

            $cacheArray[] = [$startTime, $endTime, $startString, $endString];
        }

        // sort by start-time
        uasort($cacheArray, fn($a, $b) => $a[0] - $b[0]);

        return array_map(fn($a) => [
            'start' => $a[2],
            'end' => $a[3]
        ], $cacheArray);
    }
}
