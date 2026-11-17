<?php

/**
 * Mock transcriber: returns a fixed synthetic visit (no PHI) so the workflow can be tested without AWS.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ElegantAiScribe\Provider;

class MockTranscriber implements TranscriberInterface
{
    public const SAMPLE = <<<'TXT'
Clinician: Hi, good to see you again. What brings you in today?
Patient: I've been really tired for the last three months, low energy in the afternoons, and my libido is down. I'm also not recovering from workouts like I used to.
Clinician: Any changes in sleep or weight?
Patient: Sleeping about six hours, waking up once or twice. I've gained about eight pounds since spring.
Clinician: Any medications or supplements right now?
Patient: Just vitamin D, two thousand units a day, and fish oil. No allergies that I know of.
Clinician: Your labs from last week show total testosterone of 285 and free testosterone on the low side. Vitamin D is 32. Blood pressure today is 128 over 82, weight 204 pounds.
Clinician: On exam, you look well, no thyroid enlargement, heart and lungs are normal.
Clinician: This fits with low testosterone. We talked about options: lifestyle first, sleep, strength training, and we can consider testosterone therapy. Before that I want to repeat a morning testosterone, and check LH, FSH, estradiol, PSA and a CBC.
Patient: That sounds good. I'd like to try the lifestyle changes while we wait for labs.
Clinician: Great. Aim for seven to eight hours of sleep, keep the vitamin D. We'll follow up in two weeks to review the repeat labs and decide on therapy.
TXT;

    public function id(): string
    {
        return 'mock';
    }

    public function start(string $audioPath, string $mime, string $sessionUuid): array
    {
        return ['status' => 'done', 'transcript' => self::SAMPLE];
    }

    public function poll(string $job): array
    {
        return ['status' => 'done', 'transcript' => self::SAMPLE];
    }

    public function cleanup(string $job): void
    {
    }
}
