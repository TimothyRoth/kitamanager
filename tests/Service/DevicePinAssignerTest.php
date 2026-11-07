<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\DevicePinAssigner;
use PHPUnit\Framework\TestCase;

final class DevicePinAssignerTest extends TestCase
{
    public function testEveryKitaGetsADistinctFourDigitPinThatIsNotInUse(): void
    {
        $assigner = $this->assignerWithPinsInUse(['1111', '2222']);
        $kitas = [new User(), (new User())->setDevicePin('1111'), new User()];

        $assigner->assignNewPins($kitas);

        $pins = array_map(fn (User $kita) => $kita->getDevicePin(), $kitas);
        foreach ($pins as $pin) {
            self::assertMatchesRegularExpression('/^\d{4}$/', $pin);
            self::assertNotContains($pin, ['1111', '2222']);
        }
        self::assertCount(3, array_unique($pins));
    }

    public function testTheOnlyFreePinIsChosen(): void
    {
        $allButOne = array_values(array_diff($this->allPins(), ['0042']));
        $kita = new User();

        $this->assignerWithPinsInUse($allButOne)->assignNewPins([$kita]);

        self::assertSame('0042', $kita->getDevicePin());
    }

    public function testRefusesWhenEveryPinIsInUse(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->assignerWithPinsInUse($this->allPins())->assignNewPins([new User()]);
    }

    /**
     * @param list<string> $pins
     */
    private function assignerWithPinsInUse(array $pins): DevicePinAssigner
    {
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findAllDevicePins')->willReturn($pins);

        return new DevicePinAssigner($repository);
    }

    /**
     * @return list<string>
     */
    private function allPins(): array
    {
        return array_map(fn (int $pin) => sprintf('%04d', $pin), range(0, 9999));
    }
}
