<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Version details.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'tool_ceaorganizador';
$plugin->version   = 2026111500;      // AAAAMMDDXX: subir en cada entrega; nunca bajar.
$plugin->requires  = 2024100700;      // Moodle 4.5.
$plugin->supported = [405, 503];      // Solo lo que cubre la matriz de CI.
$plugin->maturity  = MATURITY_BETA;   // MATURITY_STABLE cuando pase CI y la prueba en eCampus.
$plugin->release   = '0.1.0';
