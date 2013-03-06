<?php

namespace OpenSkedge\AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use OpenSkedge\AppBundle\Entity\Schedule;
use OpenSkedge\AppBundle\Form\ScheduleType;

/**
 * Schedule controller.
 *
 */
class ScheduleController extends Controller
{
    /**
     * Lists all Schedule entities.
     *
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();

        $entities = $em->getRepository('OpenSkedgeBundle:Schedule')->findAll();

        return $this->render('OpenSkedgeBundle:Schedule:index.html.twig', array(
            'entities' => $entities,
        ));
    }

    /**
     * Finds and displays a Schedule entity.
     *
     */
    public function viewAction(Request $request, $pid, $spid)
    {
        $em = $this->getDoctrine()->getManager();

        $position = $em->getRepository('OpenSkedgeBundle:Position')->find($pid);

        if (!$position) {
            throw $this->createNotFoundException('Unable to find Position entity.');
        }

        $schedulePeriod = $em->getRepository('OpenSkedgeBundle:SchedulePeriod')->find($spid);

        if(!$schedulePeriod) {
            throw $this->createNotFoundException('Unable to find SchedulePeriod entity.');
        }

        $resolution = $request->request->get('timeresolution', '1 hour');

        $deleteForm = $this->createDeleteForm($pid, $spid);

        $schedules = $em->getRepository('OpenSkedgeBundle:Schedule')->findBy(array(
            'schedulePeriod' => $spid,
            'position'       => $pid
        ));

        return $this->render('OpenSkedgeBundle:Schedule:view.html.twig', array(
            'htime'         => mktime(0,0,0,1,1),
            'resolution'    => $resolution,
            'schedulePeriod'=> $schedulePeriod,
            'position'      => $position,
            'schedules'     => $schedules,
            'delete_form'   => $deleteForm->createView(),
        ));
    }

    /**
     * Edits an existing Schedule entity.
     *
     */
    public function editAction(Request $request, $pid, $spid)
    {
        if (false === $this->get('security.context')->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException();
        }

        $em = $this->getDoctrine()->getManager();

        $position = $em->getRepository('OpenSkedgeBundle:Position')->find($pid);

        if (!$position) {
            throw $this->createNotFoundException('Unable to find Position entity.');
        }

        $schedulePeriod = $em->getRepository('OpenSkedgeBundle:SchedulePeriod')->find($spid);

        if(!$schedulePeriod) {
            throw $this->createNotFoundException('Unable to find SchedulePeriod entity.');
        }

        $availSchedules = $em->getRepository('OpenSkedgeBundle:AvailabilitySchedule')->findBy(array(
            'schedulePeriod' => $spid
        ));

        $availData = array();
        foreach($availSchedules as $avail)
        {
            /* We're using this entity as a temporary container
             * which generates a schedule based on the user's
             * availability schedule and any scheduled positions they may have.
             * 0 = Pending (treated as available if present in Twig template)
             * 1 = Unavailable (They're schedule for something else or marked unavailable)
             * 2 = Scheduled for current position
             * 3 = Available
             */
            $genAS = new Schedule();
            $genAS->setUser($avail->getUser());
            foreach($avail->getUser()->getSchedules() as $schedule) {
                $isPosition = ($schedule->getPosition()->getId() == $position->getId());
                $isSchedulePeriod = ($schedule->getSchedulePeriod()->getId() == $schedulePeriod->getId());
                for($timesect = 0; $timesect < 96; $timesect++) {
                    for ($day = 0; $day < 7; $day++) {
                        // Check the availability schedule to see if the user is available at all.
                        if($avail->getDayOffset($day, $timesect) != '0' && $isSchedulePeriod) {
                            if($isPosition && $schedule->getDayOffset($day, $timesect) == '1') {
                                $genAS->setDayOffset($day, $timesect, 2);
                            } else if(!$isPosition && $schedule->getDayOffset($day, $timesect) == '1' && $genAS->getDayOffset($day, $timesect) != '2') {
                                $genAS->setDayOffset($day, $timesect, 1);
                            } else if ($schedule->getDayOffset($day, $timesect) == '0' && $genAS->getDayOffset($day, $timesect) == 0) {
                                $genAS->setDayOffset($day, $timesect, 3);
                            }
                        } else {
                            if($avail->getDayOffset($day, $timesect) == '0') {
                                $genAS->setDayOffset($day, $timesect, 1);
                            }
                        }
                    }
                }
            }
            // Pass the user's availability schedule too, as we'll need to reference that for priorties.
            $availData[] = array('gen' => $genAS, 'schedule' => $avail);
        }

        $deleteForm = $this->createDeleteForm($pid, $spid);

        $resolution = $request->query->get('timeresolution', '1 hour');

        if ($request->getMethod() == 'POST') {
            $sectiondiv = $request->request->get('sectiondiv');
            for($timesect = 0; $timesect < 96; $timesect+=$sectiondiv) {
                for($day = 0; $day < 7; $day++) {
                    $hourtxt = "hour-".$timesect."-".$day;
                    $hour = $request->request->get($hourtxt);
                    if(!empty($hour)) {
                        foreach($hour as $uid) {
                            $schedule = $em->getRepository('OpenSkedgeBundle:Schedule')->findOneBy(array('schedulePeriod' => $spid, 'position' => $pid, 'user' => $uid));
                            $new = false;
                            if(!$schedule) {
                                $schedule = new Schedule();
                                $tuser = $em->getRepository('OpenSkedgeBundle:User')->find($uid);
                                if(!$tuser)
                                    throw $this->createNotFoundException('Unable to find User entity');
                                $schedule->setUser($tuser);
                                $schedule->setSchedulePeriod($schedulePeriod);
                                $schedule->setPosition($position);
                                $new = true;
                            }
                            for($sectpart=0; $sectpart < $sectiondiv; $sectpart++) {
                                $schedule->setDayOffset($day, $timesect+$sectpart, 1);
                            }
                            $em->persist($schedule);
                            $em->flush();
                            if($new) {
                                $mailer = $this->container->get('notify_mailer');
                                $mailer->notifyUserScheduleChange($schedule);
                            }
                        }
                    }
                }
            }

            return $this->redirect($this->generateUrl('position_schedule_view', array(
                'pid' => $pid,
                'spid' => $spid
            )));
        }

        return $this->render('OpenSkedgeBundle:Schedule:edit.html.twig', array(
            'htime'         => mktime(0,0,0,1,1),
            'resolution'    => $resolution,
            'schedulePeriod'=> $schedulePeriod,
            'position'      => $position,
            'availData'     => $availData,
            'edit'          => true,
            'deleteForm'    => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a Schedule entity.
     *
     */
    public function deleteAction(Request $request, $pid, $spid)
    {
        if (false === $this->get('security.context')->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException();
        }

        $form = $this->createDeleteForm();
        $form->bind($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $schedules = $em->getRepository('OpenSkedgeBundle:Schedule')->findBy(array(
                'schedulePeriod' => $spid,
                'position' => $pid
            ));

            if (!$schedules) {
                throw $this->createNotFoundException('Unable to find Schedule entity.');
            }
            foreach($schedules as $schedule)
            {
                $em->remove($schedule);
            }
            $em->flush();
        }

        return $this->redirect($this->generateUrl('schedule_periods'));
    }

    private function createDeleteForm()
    {
        return $this->createFormBuilder()
            ->getForm()
        ;
    }
}