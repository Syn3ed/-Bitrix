<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
    die();

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\Iblock;

class CarAvailableListComponent extends CBitrixComponent
{
    private const IBLOCK_CARS = 'company_cars';
    private const IBLOCK_COMFORT = 'comfort_levels';
    private const IBLOCK_ACCESS = 'position_car_access';
    private const IBLOCK_BOOKINGS = 'car_bookings';
    private const IBLOCK_EMPLOYEES = 'employee_positions'; 

    public function executeComponent()
    {
        if (!Loader::includeModule('iblock')) {
            $this->arResult['ERROR'] = 'Модуль инфоблоков не установлен';
            return $this->arResult;
        }

        global $USER;
        if (!$USER->IsAuthorized()) {
            $this->arResult['ERROR'] = 'Необходимо авторизоваться.';
            return $this->arResult;
        }

        $request = \Bitrix\Main\Context::getCurrent()->getRequest();
        $dateFromStr = $request->getQuery('date_from');
        $dateToStr = $request->getQuery('date_to');

        if (empty($dateFromStr) || empty($dateToStr)) {
            $this->arResult['ERROR'] = 'Необходимо указать временя поездки';
            return $this->arResult;
        }

        if (!CheckDateTime($dateFromStr) || !CheckDateTime($dateToStr)) {
            $this->arResult['ERROR'] = 'Неверный формат даты';
            return $this->arResult;
        }

        $dateFrom = new DateTime($dateFromStr);
        $dateTo = new DateTime($dateToStr);

        if ($dateFrom->getTimestamp() >= $dateTo->getTimestamp()) {
            $this->arResult['ERROR'] = 'Дата начала поездки не может быть больше или равна дате окончания';
            return $this->arResult;
        }

        $positionId = $this->getUserPositionId((int) $USER->GetID());
        if ($positionId <= 0) {
            $this->arResult['ERROR'] = 'Для вас не настроена должность';
            return $this->arResult;
        }

        $allowedComfortIds = $this->getAllowedComfortLevels($positionId);
        if (empty($allowedComfortIds)) {
            $this->arResult['ITEMS'] = [];
            return $this->arResult;
        }

        $bookedCarIds = $this->getBookedCarIds($dateFrom, $dateTo);

        $this->arResult['ITEMS'] = $this->getAvailableCars($allowedComfortIds, $bookedCarIds);



        return $this->arResult;
    }

    private function getIblockIdByCode(string $code): int
    {
        if (empty($code))
            return 0;

        $iblock = IblockTable::getList([
            'filter' => ['=CODE' => $code, '=ACTIVE' => 'Y'],
            'select' => ['ID']
        ])->fetch();

        return $iblock ? (int) $iblock['ID'] : 0;
    }

    private function getUserPositionId(int $userId): int
    {
        $iblockId = $this->getIblockIdByCode(self::IBLOCK_EMPLOYEES);
        if ($iblockId <= 0)
            return 0;

        $res = CIBlockElement::GetList(
            [],
            [
                'IBLOCK_ID' => $iblockId,
                'PROPERTY_USER' => $userId, 
                'ACTIVE' => 'Y'
            ],
            false,
            false,
            ['ID', 'PROPERTY_POSITION']
        );

        if ($row = $res->Fetch()) {
            return (int) $row['PROPERTY_POSITION_VALUE'];
        }

        return 0;
    }

    private function getAllowedComfortLevels(int $positionId): array
    {
        $iblockId = $this->getIblockIdByCode(self::IBLOCK_ACCESS);
        if ($iblockId <= 0)
            return [];

        $allowedLevels = [];
        $res = CIBlockElement::GetList(
            [],
            [
                'IBLOCK_ID' => $iblockId,
                'ID' => $positionId, 
                'ACTIVE' => 'Y'
            ],
            false,
            false,
            ['ID', 'PROPERTY_ALLOWED_LEVELS']
        );

        while ($row = $res->Fetch()) {
            if (!empty($row['PROPERTY_ALLOWED_LEVELS_VALUE'])) {
                $allowedLevels[] = (int) $row['PROPERTY_ALLOWED_LEVELS_VALUE'];
            }
        }

        return array_unique($allowedLevels);
    }

    private function getBookedCarIds(DateTime $dateFrom, DateTime $dateTo): array
    {
        $iblockId = $this->getIblockIdByCode(self::IBLOCK_BOOKINGS);
        if ($iblockId <= 0)
            return [];

        $bookedCarIds = [];

        $res = CIBlockElement::GetList(
            [],
            [
                'IBLOCK_ID' => $iblockId,
                'ACTIVE' => 'Y',
                '<PROPERTY_DATE_FROM' => $dateTo->format('Y-m-d H:i:s'),
                '>PROPERTY_DATE_TO' => $dateFrom->format('Y-m-d H:i:s'),
            ],
            false,
            false,
            ['ID', 'PROPERTY_CAR']
        );

        while ($row = $res->Fetch()) {
            if (!empty($row['PROPERTY_CAR_VALUE'])) {
                $bookedCarIds[] = (int) $row['PROPERTY_CAR_VALUE'];
            }
        }

        return array_unique($bookedCarIds);
    }

    private function getAvailableCars(array $allowedComfortIds, array $bookedCarIds): array
    {
        $carsIblockId = $this->getIblockIdByCode(self::IBLOCK_CARS);
        $comfortIblockId = $this->getIblockIdByCode(self::IBLOCK_COMFORT);
        $employeesIblockId = $this->getIblockIdByCode(self::IBLOCK_EMPLOYEES); 
        
        if ($carsIblockId <= 0)
            return [];

        $comfortNames = [];
        if ($comfortIblockId > 0 && !empty($allowedComfortIds)) {
            $resComfort = CIBlockElement::GetList(
                [],
                ['IBLOCK_ID' => $comfortIblockId, 'ID' => $allowedComfortIds, 'ACTIVE' => 'Y'],
                false,
                false,
                ['ID', 'NAME']
            );
            while ($comfort = $resComfort->Fetch()) {
                $comfortNames[$comfort['ID']] = $comfort['NAME'];
            }
        }

        $filter = [
            'IBLOCK_ID' => $carsIblockId,
            'ACTIVE' => 'Y',
            'PROPERTY_COMFORT_LEVEL' => $allowedComfortIds
        ];

        if (!empty($bookedCarIds)) {
            $filter['!ID'] = $bookedCarIds;
        }

        $resCars = CIBlockElement::GetList(
            ['NAME' => 'ASC'],
            $filter,
            false,
            false,
            ['ID', 'NAME', 'PROPERTY_DRIVER', 'PROPERTY_COMFORT_LEVEL']
        );

        $tempCars = [];
        $driverIds = [];

        while ($car = $resCars->Fetch()) {
            $driverId = (int)$car['PROPERTY_DRIVER_VALUE'];
            if ($driverId > 0) {
                $driverIds[] = $driverId; 
            }
            $tempCars[] = $car;
        }
 
        $driverNames = [];
        if (!empty($driverIds) && $employeesIblockId > 0) {
            $resDrivers = CIBlockElement::GetList(
                [],
                [
                    'IBLOCK_ID' => $employeesIblockId, 
                    'ID' => array_unique($driverIds), 
                    'ACTIVE' => 'Y'
                ],
                false,
                false,
                ['ID', 'NAME']
            );
            while ($driver = $resDrivers->Fetch()) {
                $driverNames[$driver['ID']] = $driver['NAME'];
            }
        }

        $availableCars = [];
        foreach ($tempCars as $car) {
            $comfortId = $car['PROPERTY_COMFORT_LEVEL_VALUE'];
            $driverId = (int)$car['PROPERTY_DRIVER_VALUE'];

            $availableCars[] = [
                'ID' => $car['ID'],
                'MODEL' => $car['NAME'],
                'DRIVER' => $driverNames[$driverId],
                'COMFORT_CATEGORY' => $comfortNames[$comfortId]
            ];
        }

        return $availableCars;
    }
}