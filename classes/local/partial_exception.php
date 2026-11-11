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
 * A batch of moves that stopped halfway, with what was recorded.
 *
 * @package    tool_ceaorganizador
 * @copyright  2026 Universidad de San Buenaventura Cali
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ceaorganizador\local;

/**
 * Thrown by batchrunner when a chunk fails after at least one chunk was committed.
 *
 * The courses moved before the failure stay moved and are recorded in the journal row $logid, which can be undone.
 * The web services turn it into the status 'partial', so the panels can offer «Deshacer» for that row.
 */
class partial_exception extends \moodle_exception {
    /** @var int Journal row with what was moved. */
    public $logid;

    /** @var int Courses moved and recorded. */
    public $moved;

    /** @var int Courses the batch meant to move. */
    public $total;

    /**
     * Constructor.
     *
     * @param int $logid
     * @param int $moved
     * @param int $total
     * @param \Throwable $cause The failure of the chunk; its message goes to the debug information.
     */
    public function __construct(int $logid, int $moved, int $total, \Throwable $cause) {
        $this->logid = $logid;
        $this->moved = $moved;
        $this->total = $total;
        parent::__construct(
            'error_partialbatch',
            'tool_ceaorganizador',
            '',
            (object) ['moved' => $moved, 'total' => $total],
            $cause->getMessage()
        );
    }
}
