<?php

$flyingPig = TRUE;

if ($flyingPig === TRUE) {
	if (extension_loaded('atatus')) {
    	atatus_notify_exception("Pig should not be able to fly");
  	}
}