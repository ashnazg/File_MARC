<?php

/* vim: set expandtab shiftwidth=4 tabstop=4 softtabstop=4 foldmethod=marker: */

/**
 * Parser for MARC records
 *
 * This package is based on the PHP MARC package, originally called "php-marc",
 * that is part of the Emilda Project (http://www.emilda.org). Christoffer
 * Landtman generously agreed to make the "php-marc" code available under the
 * GNU LGPL so it could be used as the basis of this PEAR package.
 * 
 * PHP version 5
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation; either version 2.1 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category  File_Formats
 * @package   File_MARC
 * @author    Dan Scott <dscott@laurentian.ca>
 * @copyright 2007-2008 Dan Scott
 * @license   http://www.gnu.org/copyleft/lesser.html  LGPL License 2.1
 * @version   CVS: $Id$
 * @link      http://pear.php.net/package/File_MARC
 * @example   read.php Retrieve specific fields and subfields from a record
 * @example   subfields.php Create new subfields and add them in specific order
 * @example   marc_yaz.php Pretty print a MARC record retrieved through the PECL yaz extension
 */

require_once 'PEAR/Exception.php';
require_once 'Structures/LinkedList/Double.php';
require_once 'File/MARC.php';
require_once 'File/MARC/Record.php';
require_once 'File/MARC/Field.php';
require_once 'File/MARC/Control_Field.php';
require_once 'File/MARC/Data_Field.php';
require_once 'File/MARC/Subfield.php';
require_once 'File/MARC/Exception.php';
require_once 'File/MARC/List.php';

// {{{ class File_MARCXML
/**
 * The main File_MARCXML class enables you to return File_MARC_Record
 * objects from an XML stream or string.
 *
 * @category File_Formats
 * @package  File_MARC
 * @author   Dan Scott <dscott@laurentian.ca>
 * @license  http://www.gnu.org/copyleft/lesser.html  LGPL License 2.1
 * @link     http://pear.php.net/package/File_MARC
 */
class File_MARCXML
{

    // {{{ constants

    /**
     * MARC records retrieved from a file
     */
    const SOURCE_FILE = 1;

    /**
     * MARC records retrieved from a binary string 
     */
    const SOURCE_STRING = 2;
    // }}}

    // {{{ properties
    /**
     * Source containing raw records
     * 
     * @var resource
     */
    protected $source;

    /**
     * Source type (SOURCE_FILE or SOURCE_STRING)
     * 
     * @var int
     */
    protected $type;

    /**
     * Counter for MARCXML records in a collection
     *
     * @var int
     */
    protected $counter;
    // }}}

    // {{{ Constructor: function __construct()
    /**
     * Read in MARCXML records
     *
     * This function reads in files or strings that
     * contain one or more MARCXML records.
     *
     * <code>
     * <?php
     * // Retrieve MARC records from a file
     * $journals = new File_MARC('journals.mrc', SOURCE_FILE);
     *
     * // Retrieve MARC records from a string (e.g. Z39 query results)
     * $monographs = new File_MARC($raw_marc, SOURCE_STRING);
     * ?>
     * </code>
     *
     * @param string $source Name of the file, or a raw MARC string
     * @param int    $type   Source of the input, either SOURCE_FILE or SOURCE_STRING
     */
    function __construct($source, $type = self::SOURCE_FILE)
    {
        $this->counter = 0;

        switch ($type) {

        case self::SOURCE_FILE:
            $this->type = self::SOURCE_FILE;
            $this->source = simplexml_load_file($source);
            break;

        case self::SOURCE_STRING:
            $this->type = self::SOURCE_STRING;
            $this->source = simplexml_load_string($source);
            break;

        default:
             throw new File_MARC_Exception(File_MARC_Exception::$messages[File_MARC_Exception::ERROR_INVALID_SOURCE], File_MARC_Exception::ERROR_INVALID_SOURCE);
        }

        if (!$this->source) {
            $errorMessage = File_MARC_Exception::formatError(File_MARC_Exception::$messages[File_MARC_Exception::ERROR_INVALID_FILE], array('filename' => $source));
            throw new File_MARC_Exception($errorMessage, File_MARC_Exception::ERROR_INVALID_FILE);
        }
    }
    // }}}

    // {{{ next()
    /**
     * Return next {@link File_MARC_Record} object
     *
     * Decodes the next MARCXML record and returns the {@link File_MARC_Record}
     * object.
     * <code>
     * <?php
     * // Retrieve a set of MARCXML records from a file
     * $journals = new File_MARCXML('journals.xml', SOURCE_FILE);
     *
     * // Iterate through the retrieved records
     * while ($record = $journals->next()) {
     *     print $record;
     *     print "\n";
     * }
     *
     * ?>
     * </code>
     *
     * @return File_MARC_Record next record, or false if there are
     * no more records
     */
    function next()
    {
        if (isset($this->source->record[$this->counter])) {
            $record = $this->source->record[$this->counter++];
        } elseif ($this->source->getName() == "record" && $this->counter == 0) {
            $record = $this->source;
            $this->counter++;
        } else {
            return false;
        }
        
        if ($record) {
            return $this->_decode($record);
        } else {
            return false;
        }
    }
    // }}}

    // {{{ _decode()
    /**
     * Decode a given MARCXML record
     *
     * @param string $text MARCXML record element
     *
     * @return File_MARC_Record Decoded File_MARC_Record object
     */
    private function _decode($text)
    {
        $marc = new File_MARC_Record();

        // Store leader
        $marc->setLeader($text->leader);

        // go through all the control fields
        foreach ($text->controlfield as $controlfield) {
            $marc->appendField(new File_MARC_Control_Field($controlfield['tag'], $controlfield));
        }

        // go through all the data fields
        foreach ($text->datafield as $datafield) {
            $subfield_data = array();
            foreach ($datafield->subfield as $subfield) {
                $subfield_data[] = new File_MARC_Subfield($subfield['code'], $subfield);
            }
            $marc->appendField(new File_MARC_Data_Field($datafield['tag'], $subfield_data, $datafield['ind1'], $datafield['ind2']));
        }

        return $marc;
    }
    // }}}

}
// }}}

