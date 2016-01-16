<?php
class NewGameFactory implements ContentFactoryInterface {
	
	public function getName() {
		return 'newgame';
	}
	
	public function getOperation($id_user,$id_game) {
		$return = new ContentNewGame($id_user,$id_game,'newgame',CHECK_SESSION_USER);
		return $return;
	}
}
?>