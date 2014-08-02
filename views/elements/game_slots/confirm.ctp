<li><span class="name"><?php echo $facility['name'] . ' ' . $field['num']; ?></span>
<div<?php if (!isset($expanded) || !$expanded) echo ' class="hidden"'; ?>>
<?php
foreach ($weeks as $key => $week) {
  echo $week;
  foreach ($times as $key2 => $time) {
	echo $this->Form->input("GameSlot.Create.{$field['id']}.$key.$key2", array(
			//'div' => true,
			//'label' => $week . ' ' . $time,
			'label' => $time,
			'type' => 'checkbox',
			'hiddenField' => false,
			'checked' => true,
	));
  }
}
?>
</div>
</li>
