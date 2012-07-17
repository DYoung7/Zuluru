<?php
/**
 * Rule helper for checking whether the user has a required document.
 */

class RuleHasDocumentComponent extends RuleComponent
{
	var $reason = 'have uploaded the required document';

	function parse($config) {
		$this->config = array_map ('trim', explode (',', $config));
		foreach ($this->config as $key => $val) {
			$this->config[$key] = trim ($val, '"\'');
		}
		return (count($this->config) == 2);
	}

	// Check if the user has uploaded the required document
	function evaluate($params) {
		if (is_array($params) && array_key_exists ('Upload', $params)) {
			$date = date('Y-m-d', strtotime ($this->config[1]));
			$matches = Set::extract ("/Upload[type_id={$this->config[0]}][valid_from<=$date][valid_until>=$date]", $params);
			if (!empty ($matches)) {
				return true;
			}
		}
		return false;
	}

	function query() {
		$date = date('Y-m-d', strtotime ($this->config[1]));
		return $this->_execute_query(
			array(
				'Upload.type_id' => $this->config[0],
				'Upload.approved' => true,
				'Upload.valid_from <=' => $date,
				'Upload.valid_until >=' => $date,
			),
			array('Upload' => array(
				'table' => 'uploads',
				'alias' => 'Upload',
				'type' => 'LEFT',
				'foreignKey' => false,
				'conditions' => 'Upload.person_id = Person.id',
			))
		);
	}

	function desc() {
		return __('have the document', true);
	}
}

?>