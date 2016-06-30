<?php
namespace AttOn\View;

use AttOn\Exceptions\MapException;
use AttOn\Model\Atton\InGame\ModelInGameLandUnit;
use AttOn\Model\Atton\InGame\ModelInGameShip;
use AttOn\Model\Atton\ModelLandUnit;
use AttOn\Model\DataBase\DataSource;
use AttOn\Model\DataBase\SQLCommands;
use AttOn\Model\Game\ModelGame;

class Map {

    public function run(array &$data) {
        $game = ModelGame::getCurrentGame();
        $id_game = $game->getId();
        SQLCommands::init($id_game);

        // running game (or newly started but countries are already picked)
        if (($game->getStatus() === GAME_STATUS_RUNNING) || (($game->getStatus() === GAME_STATUS_STARTED) && ($game->getIdPhase() === PHASE_SETSHIPS))) {
            $query = 'get_map_for_running_game';
        } // newly started game countries have to be picked
        else if (($game->getStatus() === GAME_STATUS_STARTED) && ($game->getIdPhase() === PHASE_SELECTSTART)) {
            $query = 'get_map_for_new_game';
        } // game not in valid phase
        else {
            throw new MapException('invalid game selected: ' . $id_game);
        }
        $result = DataSource::getInstance()->epp($query);

        $countryData = array();
        foreach ($result as $country) {
            // newly started game countries have to be picked -> no landunits/ships available
            if (array_key_exists('countrySelectOption', $country)) {
                $countryData[] = $country;
                continue;
            }

            // running game (or newly started but countries are already picked)
            // check landunits
            $unitCount = 0;
            $id_user = (int)$country['id_user'];
            if ($id_user <= 0) {
                $id_user = NEUTRAL_COUNTRY;
            }
            $units = ModelInGameLandUnit::getUnitsByIdZAreaUser($id_game, (int)$country['id'], $id_user);
            $unitsViewData = array();
            /* @var $unit ModelInGameLandUnit */
            foreach ($units as $unit) {
                $unitCount += $unit->getCount();
                $landUnit = ModelLandUnit::getModelById($unit->getIdUnit());
                $unitViewData = array(
                    'name' => $landUnit->getName(),
                    'count' => $unit->getCount()
                );
                $unitsViewData[] = $unitViewData;
            }
            if ($unitCount > 0) {
                $country['units'] = $unitsViewData;
            }
            $country['unitCount'] = $unitCount;

            // check ships
            $shipCount = 0;
            if ((int)$country['area_type'] === TYPE_LAND) {
                // TODO : implement
                //$ships = ModelInGameShip::getShipsInPortByUser();
            } else if ((int)$country['area_type'] === TYPE_SEA) {
                // TODO : implement
                //$ships = ModelInGameShip::getShipsInAreaNotInPortByUser();
            }
            $country['shipCount'] = $shipCount;

            $countryData[] = $country;
        }
        $data['countryData'] = $countryData;

        return $data;
    }

}
