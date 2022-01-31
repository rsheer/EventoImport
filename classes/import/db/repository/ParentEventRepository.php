<?php declare(strict_types = 1);

namespace EventoImport\import\db\repository;

use EventoImport\import\db\model\IliasEventoParentEvent;

class ParentEventRepository
{
    public const TABLE_NAME = 'crevento_parent_events';

    public const COL_GROUP_UNIQUE_KEY = 'group_unique_key';
    public const COL_GROUP_EVENTO_ID = 'group_evento_id';
    public const COL_REF_ID = 'ref_id';
    public const COL_TITLE = 'title';
    public const COL_ADMIN_ROLE_ID = 'admin_role_id';
    public const COL_STUDENT_ROLE_ID = 'student_role_id';

    private $db;

    public function __construct(\ilDBInterface $db)
    {
        $this->db = $db;
    }

    private function buildParentEventObjectFromRow($row) : IliasEventoParentEvent
    {
        return new IliasEventoParentEvent(
            $row[self::COL_GROUP_UNIQUE_KEY],
            $row[self::COL_GROUP_EVENTO_ID],
            $row[self::COL_TITLE],
            $row[self::COL_REF_ID],
            $row[self::COL_ADMIN_ROLE_ID],
            $row[self::COL_STUDENT_ROLE_ID],
        );
    }

    public function addNewParentEvent(\EventoImport\import\db\model\IliasEventoParentEvent $parent_event) : void
    {
        $this->db->insert(
            // INSERT INTO
            self::TABLE_NAME,

            // VALUES
            [
                // id
                self::COL_GROUP_UNIQUE_KEY => [\ilDBConstants::T_TEXT, $parent_event->getGroupUniqueKey()],

                // foreign keys
                self::COL_TITLE => [\ilDBConstants::T_TEXT, $parent_event->getTitle()],
                self::COL_REF_ID => [\ilDBConstants::T_INTEGER, $parent_event->getRefId()],
                self::COL_ADMIN_ROLE_ID => [\ilDBConstants::T_INTEGER, $parent_event->getAdminRoleId()],
                self::COL_STUDENT_ROLE_ID => [\ilDBConstants::T_INTEGER, $parent_event->getStudentRoleId()],
            ]
        );
    }

    public function fetchParentEventByGroupUniqueKey(string $group_unique_key) : ?IliasEventoParentEvent
    {
        $query = 'SELECT * FROM ' . self::TABLE_NAME . ' WHERE ' . self::COL_GROUP_UNIQUE_KEY . ' = ' . $this->db->quote($group_unique_key, \ilDBConstants::T_TEXT);
        $result = $this->db->query($query);

        if ($row = $this->db->fetchAssoc($result)) {
            return $this->buildParentEventObjectFromRow($row);
        }

        return null;
    }

    public function fetchParentEventForName(string $name) : ?IliasEventoParentEvent
    {
        $query = 'SELECT * FROM ' . self::TABLE_NAME . ' WHERE ' . self::COL_TITLE . ' = ' . $this->db->quote($name, \ilDBConstants::T_TEXT);
        $result = $this->db->query($query);

        if ($row = $this->db->fetchAssoc($result)) {
            return $this->buildParentEventObjectFromRow($row);
        }

        return null;
    }

    public function fetchParentEventForRefId(int $ref_id)
    {
        $query = 'SELECT * FROM ' . self::TABLE_NAME . ' WHERE ' . self::COL_REF_ID . ' = ' . $this->db->quote($ref_id, \ilDBConstants::T_INTEGER);
        $result = $this->db->query($query);

        if ($row = $this->db->fetchAssoc($result)) {
            return $this->buildParentEventObjectFromRow($row);
        }

        return null;
    }
}
