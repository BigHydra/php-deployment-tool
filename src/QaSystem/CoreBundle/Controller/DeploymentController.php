<?php

namespace QaSystem\CoreBundle\Controller;

use QaSystem\CoreBundle\Entity\DeploymentRepository;
use Symfony\Component\HttpFoundation\Request;
use QaSystem\CoreBundle\Form\DeploymentType;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use QaSystem\CoreBundle\Entity\Deployment;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Deployment controller.
 *
 * @Route("/deployment")
 */
class DeploymentController extends Controller
{

    /**
     * Lists all Deployment entities.
     *
     * @Route("/", name="deployment")
     * @Method("GET")
     * @Template()
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();

        $status = $this->get('request')->query->get('status', 'all');

        /** @var DeploymentRepository $repo */
        $repo = $em->getRepository('QaSystemCoreBundle:Deployment');
        $query = $repo->createQueryBuilderForPagination($status)
            ->getQuery();

        $paginator  = $this->get('knp_paginator');
        $entities = $paginator->paginate(
            $query,
            $this->get('request')->query->get('page', 1),
            10
        );

        return array(
            'entities' => $entities,
            'selectedStatus' => $status,
        );
    }

    /**
     * Creates a new Deployment entity.
     *
     * @Route("/", name="deployment_create")
     * @Method("POST")
     * @Template("QaSystemCoreBundle:Deployment:new.html.twig")
     */
    public function createAction(Request $request)
    {
        $deployment = new Deployment();
        $form = $this->createCreateForm($deployment);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->get('doctrine.orm.default_entity_manager');

            $em->getConnection()->beginTransaction();

            try {
                $em->persist($deployment);
                $em->flush();

                $msg = array(
                    'deploymentId' => $deployment->getId()
                );

                $this->get('old_sound_rabbit_mq.project_deploy_producer')->publish(serialize($msg));

                $em->getConnection()->commit();
            } catch (\Exception $e) {
                $em->getConnection()->rollback();

                throw $e;
            }

            return $this->redirect($this->generateUrl('deployment_show', array('id' => $deployment->getId())));
        }

        return array(
            'entity' => $deployment,
            'form'   => $form->createView(),
        );
    }

    /**
     * Creates a form to create a Deployment entity.
     *
     * @param Deployment $deployment The entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createCreateForm(Deployment $deployment)
    {
        $form = $this->createForm(
            new DeploymentType($this->get('qa_system_core.version_control')),
            $deployment,
            array(
                'action' => $this->generateUrl('deployment_create'),
                'method' => 'POST',
            )
        );

        $form->add('submit', 'submit', array('label' => 'Create'));

        return $form;
    }

    /**
     * Displays a form to create a new Recipe entity.
     *
     * @Route("/new", name="deployment_new")
     * @Method("GET")
     * @Template()
     */
    public function newAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $projectId = $request->get('project_id');

        if (!$projectId) {
            throw $this->createNotFoundException(sprintf('Parameter project_id is missing'));
        }

        $project = $em->getRepository('QaSystemCoreBundle:Project')->find($projectId);

        if (!$project) {
            throw new NotFoundHttpException(sprintf('Project %d not found', $projectId));
        }

        $versionControlService = $this->get('qa_system_core.version_control');

        $deployment = new Deployment();
        $deployment->setProject($project);
        $form = $this->createCreateForm($deployment, $versionControlService->getBranches($deployment->getProject()));

        return array(
            'entity' => $deployment,
            'form'   => $form->createView(),
        );
    }

    /**
     * Finds and displays a Deployment entity.
     *
     * @Route("/{id}", name="deployment_show")
     * @Method("GET")
     * @Template()
     */
    public function showAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('QaSystemCoreBundle:Deployment')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Deployment entity.');
        }

        return array(
            'entity' => $entity,
        );
    }

    /**
     * Abort a deployment.
     *
     * @Route("/abort/{id}", name="deployment_abort")
     * @Method("GET")
     */
    public function abortAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        /** @var Deployment $entity */
        $entity = $em->getRepository('QaSystemCoreBundle:Deployment')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find Deployment entity.');
        }

        if ($entity->getStatus() === Deployment::STATUS_DEPLOYING) {
            $this->get('qa_system_core.deployment_tool')->abort($entity);
        }

        return $this->redirect($this->generateUrl('deployment'));
    }
}
