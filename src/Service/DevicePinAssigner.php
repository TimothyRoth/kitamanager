<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;

/**
 * Gives Kitas fresh random device PINs that collide with no PIN in use.
 */
final class DevicePinAssigner
{
    private const PIN_SPACE = 10000;

    public function __construct(private readonly UserRepository $userRepository)
    {
    }

    /**
     * @param iterable<User> $kitas
     */
    public function assignNewPins(iterable $kitas): void
    {
        $taken = array_fill_keys($this->userRepository->findAllDevicePins(), true);

        foreach ($kitas as $kita) {
            $pin = $this->unusedPin($taken);
            $taken[$pin] = true;
            $kita->setDevicePin($pin);
        }
    }

    /**
     * @param array<string, true> $taken
     */
    private function unusedPin(array $taken): string
    {
        if (count($taken) >= self::PIN_SPACE) {
            throw new \RuntimeException('Every 4-digit device PIN is already in use.');
        }

        do {
            $pin = sprintf('%04d', random_int(0, self::PIN_SPACE - 1));
        } while (isset($taken[$pin]));

        return $pin;
    }
}
