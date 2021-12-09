<?php

namespace EventoImport\import\db;

use EventoImport\import\db\repository\EventMembershipRepository;
use ILIAS\DI\RBACServices;
use EventoImport\communication\api_models\EventoEvent;
use EventoImport\import\db\repository\EventoUserRepository;
use EventoImport\communication\api_models\EventoUserShort;
use EventoImport\import\db\model\IliasEventoEvent;
use EventoImport\import\db\repository\IliasEventoEventsRepository;
use EventoImport\import\db\model\IliasEventoUser;
use phpDocumentor\Reflection\Types\Self_;

class MembershipManager
{
    /**
     * @var EventMembershipRepository
     */
    private $membership_repo;
    private $favourites_manager;

    private const ROLE_ADMIN = 1;
    private const ROLE_MEMBER = 2;

    public function __construct(
        EventMembershipRepository $membership_repo,
        EventoUserRepository $user_repo,
        IliasEventoEventsRepository $event_repo,
        \ilFavouritesManager $favourites_manager,
        RBACServices $rbac_services
    ) {
        $this->membership_repo = $membership_repo;
        $this->user_repo = $user_repo;
        $this->event_repo = $event_repo;
        $this->favourites_manager = $favourites_manager;
        $this->rbac_review = $rbac_services->review();
        $this->rbac_admin = $rbac_services->admin();
    }

    private function getRoleIdForObjectOrNull(\ilObject $parent_membership_object, int $role_type) : ?int
    {
        if ($role_type != self::ROLE_ADMIN && $role_type != self::ROLE_MEMBER) {
            return null;
        }

        if ($parent_membership_object instanceof \ilObjCourse) {
            return $role_type == self::ROLE_ADMIN
                ? $parent_membership_object->getDefaultAdminRole()
                : $parent_membership_object->getDefaultMemberRole();
        } elseif ($parent_membership_object instanceof \ilObjGroup) {
            return $role_type == self::ROLE_ADMIN
                ? $parent_membership_object->getDefaultAdminRole()
                : $parent_membership_object->getDefaultMemberRole();
        }

        return null;
    }

    private function searchMembershipParentObjectsForEvent(int $current_event_ref_id) : array
    {
        global $DIC;
        $parent_objects = [];

        $current_ref_id = $current_event_ref_id;
        $deadlock_prevention = 0;
        do {
            $current_ref_id = $DIC->repositoryTree()->getParentId($current_ref_id);
            $type = \ilObject::_lookupType($current_ref_id, true);

            if ($type == 'crs') {
                $parent_objects[] = new \ilObjCourse($current_ref_id, true);
            } elseif ($type == 'grp') {
                $parent_objects[] = $crs_obj = new \ilObjCourse($current_ref_id, true);
            }
            $deadlock_prevention++;
        } while (in_array($type, ['crs', 'grp', 'fold']) && $current_ref_id > 1 && $deadlock_prevention < 100);

        return $parent_objects;
    }

    private function isMemberInCurrentImport($member, array $evento_user_list) : bool
    {
        return true;
    }

    private function addUsersToEvent(
        IliasEventoEvent $ilias_evento_event,
        array $evento_user_list,
        int $role_id_of_main_event,
        array $parent_membership_objects,
        int $role_type
    )
    {
        /** @var EventoUserShort $evento_user */
        foreach ($evento_user_list as $evento_user) {
            $user_id = $this->user_repo->getIliasUserIdByEventoId($evento_user->getEventoId());

            if (!$this->rbac_review->isAssigned($user_id, $role_id_of_main_event)) {
                $this->rbac_admin->assignUser($role_id_of_main_event, $user_id);
                $this->favourites_manager->add($user_id, $ilias_evento_event->getRefId());
            }

            /** @var \ilObject $parent_membership_object */
            foreach ($parent_membership_objects as $parent_membership_object) {
                $role_id = $this->getRoleIdForObjectOrNull($parent_membership_object, $role_type);

                if (!is_null($role_id) && !$this->rbac_review->isAssigned($user_id, $role_id)) {
                    $this->rbac_admin->assignUser($role_id, $user_id);
                    $this->favourites_manager->add($user_id, $parent_membership_object->getRefId());
                }
            }
            $this->membership_repo->addMembershipIfNotExist($ilias_evento_event->getEventoEventId(), $user_id, $role_type);
        }
    }

    private function removeUsersFromEvent(
        IliasEventoEvent $ilias_evento_event,
        array $evento_user_list,
        int $role_id_of_main_event,
        array $from_import_subscribed_members,
        array $parent_membership_objects,
        int $role_type
    )
    {
        /** @var IliasEventoUser $member */
        foreach ($from_import_subscribed_members as $member) {

            // Check if user was in current import
            if (!$this->isMemberInCurrentImport($member, $evento_user_list)) {

                // Always remove from main event
                if (!$this->rbac_review->isAssigned($member->getIliasUserId(), $role_id_of_main_event)) {
                    $this->rbac_admin->deassignUser($role_id_of_main_event, $member->getIliasUserId());
                    $this->favourites_manager->remove($member->getIliasUserId(), $ilias_evento_event->getRefId());
                }

                if (!is_null($ilias_evento_event->getParentEventKey())
                    && !$this->membership_repo->checkIfUserHasMembershipInOtherSubEvent(
                        $ilias_evento_event->getParentEventKey(),
                        $member->getEventoUserId(),
                        $ilias_evento_event->getEventoEventId()
                    )
                ) {
                    foreach ($parent_membership_objects as $parent_membership_object) {
                        $role_id = $this->getRoleIdForObjectOrNull($parent_membership_object, $role_type);

                        if (!is_null($role_id) && !$this->rbac_review->isAssigned($member->getIliasUserId(), $parent_membership_object)) {
                            $this->rbac_admin->deassignUser($role_id, $member->getIliasUserId());
                            $this->favourites_manager->add($member->getIliasUserId(), $parent_membership_object->getRefId());
                        }
                    }
                }
            }
        }
    }

    private function synchronizeRolesWithMembers(
        IliasEventoEvent $ilias_evento_event,
        array $evento_user_list,
        int $role_id_of_main_event,
        array $parent_membership_objects,
        int $role_type
    ) {

        // Add
        $this->addUsersToEvent(
            $ilias_evento_event,
            $evento_user_list,
            $role_id_of_main_event,
            $parent_membership_objects,
            $role_type
        );

        // Remove
        $from_import_subscribed_members = $this->membership_repo->fetchIliasEventoUsersForEventAndRole($ilias_evento_event->getEventoEventId(), $role_type);
        if (count($from_import_subscribed_members) != count($evento_user_list)) {
            $this->removeUsersFromEvent(
                $ilias_evento_event,
                $evento_user_list,
                $role_id_of_main_event,
                $from_import_subscribed_members,
                $parent_membership_objects,
                $role_type
            );
        }
    }

    public function synchronizeMembershipsWithEvent(EventoEvent $evento_event, IliasEventoEvent $ilias_evento_event)
    {
        $this->synchronizeRolesWithMembers(
            $ilias_evento_event,
            $evento_event->getEmployees(),
            $ilias_evento_event->getAdminRoleId(),
            $this->searchMembershipParentObjectsForEvent($ilias_evento_event->getRefId()),
            EventMembershipRepository::ROLE_ADMIN
        );

        $this->synchronizeRolesWithMembers(
            $ilias_evento_event,
            $evento_event->getStudents(),
            $ilias_evento_event->getStudentRoleId(),
            $this->searchMembershipParentObjectsForEvent($ilias_evento_event->getRefId()),
            EventMembershipRepository::ROLE_MEMBER
        );
    }
}
