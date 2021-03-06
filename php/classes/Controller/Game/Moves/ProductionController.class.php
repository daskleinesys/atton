<?php
namespace Attack\Controller\Game\Moves;

use Attack\Controller\Interfaces\PhaseController;
use Attack\Exceptions\ControllerException;
use Attack\Model\Game\ModelGameArea;
use Attack\Model\Game\Moves\ModelProductionMove;
use Attack\Model\Units\ModelLandUnit;
use Attack\Model\Game\ModelGame;
use Attack\Tools\UserViewHelper;

class ProductionController extends PhaseController {


    /**
     * @param int $id_user - id of the user accessing the moderation actions
     * @param int $id_game - id of currently selected game
     */
    public function __construct($id_user, $id_game) {
        parent::__construct((int)$id_user, (int)$id_game, PHASE_PRODUCTION);
    }

    /**
     * fixates the move if no error occured
     *
     * @return void
     */
    public function finishMove() {
        $this->fixatePhase(true);
    }

    /**
     * validates the new move first (does the user own the country, has he/she enough res left)
     *
     * @param int $id_game_area
     * @param array $units
     * @return ModelProductionMove
     * @throws ControllerException
     * @throws \Attack\Exceptions\ModelException
     * @throws \Attack\Exceptions\NullPointerException
     */
    public function createProductionMove($id_game_area, $units) {
        $id_game_area = (int)$id_game_area;

        // check if already fixated
        if ($this->checkIfDone()) {
            throw new ControllerException('ProductionMove already finished.');
        }

        // check if processing
        $game = ModelGame::getGame($this->id_game);
        if ($game->checkProcessing()) {
            throw new ControllerException('Unable to create moves at this moment as the game-logic is currently processing.');
        }
        // check if valid area picked
        if ($id_game_area === 0) {
            throw new ControllerException('Choose a start and destination country.');
        }
        ModelGameArea::getGameArea($this->id_game, $id_game_area);
        // check for units
        $unit_count = 0;
        foreach ($units as $count) {
            if ($count < 0) {
                throw new ControllerException('No negative unit numbers allowed.');
            }
            $unit_count += $count;
        }
        if ($unit_count === 0) {
            throw new ControllerException('Choose at least one unit.');
        }

        $round = (int)$game->getRound();
        $phase = (int)$game->getIdPhase();
        if ($phase > PHASE_PRODUCTION) {
            ++$round;
        }

        $this->validateNewProductionMove($round, $id_game_area, $units);
        return ModelProductionMove::createProductionMove($this->id_user, $this->id_game, $round, $id_game_area, $units);
    }

    /**
     * check if a move exists, then flag it as deleted
     *
     * @param int $id_move
     * @return void
     * @throws ControllerException
     * @throws \Attack\Exceptions\NullPointerException
     */
    public function deleteProductionMove($id_move) {
        $id_move = intval($id_move);

        // check if move exists
        $move = ModelProductionMove::getProductionMove($this->id_game, $id_move);
        // check if move is from user
        if ($this->id_user !== $move->getIdUser()) {
            throw new ControllerException('Unable to delete move from another user.');
        }
        // check if already fixated
        if ($this->checkIfDone()) {
            throw new ControllerException('ProductionMove already finished.');
        }
        // check if processing
        $game = ModelGame::getGame($this->id_game);
        if ($game->checkProcessing()) {
            throw new ControllerException('Unable to delete moves at this moment as the game-logic is currently processing.');
        }
        // check if move is a landmove from the current round
        $move_round = $move->getRound();
        $round = $game->getRound();
        $phase = $game->getIdPhase();
        if ($phase > PHASE_PRODUCTION) {
            ++$round;
        }
        if ($round != $move_round) {
            throw new ControllerException('Unable to delete move as it is not from the correct round.');
        }

        // delete move
        $move->flagMoveDeleted();
        return;
    }

    /**
     * checks if production is valid, takes in consideration all other moves for this phase/user that are not flagged as deleted
     *
     * @param ModelProductionMove $move
     * @return void
     * @throws ControllerException $ex
     */
    public function validateProductionMove(ModelProductionMove $move) {
        // 1. check if game_area belongs to user
        $gameArea = ModelGameArea::getGameArea($move->getIdGame(), $move->getIdGameArea());
        if ($move->getIdUser() !== $gameArea->getIdUser()) {
            throw new ControllerException('Area doesn\'t belong to the user.');
        }

        // 2. check if user has enough res left
        // 2.a check cost of previous productions
        $current_costs = $move->getCost();
        $moves = ModelProductionMove::iterator($move->getIdUser(), $move->getIdGame(), $move->getRound());
        while ($moves->hasNext()) {
            /* @var $move ModelProductionMove */
            $move_tmp = $moves->next();
            if ($move_tmp === $move) {
                continue;
            }
            $current_costs += $move_tmp->getCost();
        }
        // 2.b get available res
        $current_production = UserViewHelper::getCurrentProductionForUserInGame($move->getIdUser(), $move->getIdGame());

        if ($current_production['sum'] - $current_costs < 0) {
            throw new ControllerException('Insufficient funds!');
        }
    }

    private function validateNewProductionMove($round, $id_game_area, array $units) {
        // 1. check if game_area belongs to user
        $gameArea = ModelGameArea::getGameArea($this->id_game, $id_game_area);
        if ($this->id_user !== $gameArea->getIdUser()) {
            throw new ControllerException('Unable to create production move. Area doesn\'t belong to the current user.');
        }

        // 2. check if user has enough res left
        // 2.a check cost of previous productions
        $current_costs = 0;
        $moves = ModelProductionMove::iterator($this->id_user, $this->id_game, $round);
        while ($moves->hasNext()) {
            /* @var $move ModelProductionMove */
            $move = $moves->next();
            $current_costs += $move->getCost();
        }
        // 2.b get available res
        $current_production = UserViewHelper::getCurrentProductionForUserInGame($this->id_user, $this->id_game);
        // 2.c get cost of new move
        $new_cost = 0;
        $unit_iter = ModelLandUnit::iterator();
        while ($unit_iter->hasNext()) {
            /* @var $unit ModelLandUnit */
            $unit = $unit_iter->next();
            $id_unit = (int)$unit->getId();
            if (!isset($units[$id_unit]) || $units[$id_unit] <= 0) {
                continue;
            }
            $new_cost += (int)$unit->getPrice() * $units[$id_unit];
        }
        if ($current_production['sum'] - $current_costs < $new_cost) {
            throw new ControllerException('Insufficient funds!');
        }
    }

}
