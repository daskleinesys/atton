<?php
namespace AttOn\Tools;

use AttOn\Exceptions\NullPointerException;
use AttOn\Model\Atton\InGame\ModelGameArea;
use AttOn\Model\User\ModelIsInGameInfo;

class UserViewHelper {

    /**
     * @param $id_user int
     * @param $id_game int
     * @return array (money => int, countries => int, resproduction => int, traderoutes => int, trproduction => int, combos => int, comboproduction => int, sum => int)
     * @throws NullPointerException
     */
    public static function getCurrentProductionForUserInGame($id_user, $id_game) {
        $id_user = (int)$id_user;
        $id_game = (int)$id_game;
        $output = array();

        // money on bank
        $ingame = ModelIsInGameInfo::getIsInGameInfo($id_user, $id_game);
        $output['money'] = $ingame->getMoney();
        $output['moneySpendable'] = min($output['money'], MAX_MONEY_SPENDABLE);

        // money from resources
        $output['countries'] = 0;
        $output['resproduction'] = 0;
        $combos = array();
        $combos[RESOURCE_OIL] = 0;
        $combos[RESOURCE_TRANSPORT] = 0;
        $combos[RESOURCE_INDUSTRY] = 0;
        $combos[RESOURCE_MINERALS] = 0;
        $combos[RESOURCE_POPULATION] = 0;
        $iter = ModelGameArea::iterator($id_user, $id_game);
        while ($iter->hasNext()) {
            $area = $iter->next();
            ++$output['countries'];
            $output['resproduction'] += $area->getProductivity();
            ++$combos[$area->getIdResource()];
        }

        // money from traderoutes
        $output['traderoutes'] = 0;
        $output['trproduction'] = 0;

        // money from combos
        $combo_count = $combos[RESOURCE_OIL];
        foreach ($combos as $res_count) {
            if ($res_count < $combo_count) {
                $combo_count = $res_count;
            }
        }
        $output['combos'] = $combo_count;
        $output['comboproduction'] = $combo_count * 4;

        // sum
        $output['sum'] = $output['moneySpendable'] + $output['resproduction'] + $output['trproduction'] + $output['comboproduction'];

        return $output;
    }
}
