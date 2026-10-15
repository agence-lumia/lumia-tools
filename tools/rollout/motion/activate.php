<?php
/**
 * Turns the Motion module on from WP-CLI. Inactive modules are not registered outside the admin,
 * so they are registered first; activate() then returns true (or already on: true).
 */
$p = \Lumia\Tools\Core\Plugin::instance();
$r = new ReflectionProperty( $p, 'modules' ); $r->setAccessible( true ); $m = $r->getValue( $p );
$m->register_default_modules( false );
var_dump( $m->activate( 'motion' ) );
