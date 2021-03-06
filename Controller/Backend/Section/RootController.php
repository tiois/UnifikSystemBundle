<?php

namespace Unifik\SystemBundle\Controller\Backend\Section;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

use Unifik\SystemBundle\Entity\AppRepository;
use Unifik\SystemBundle\Entity\Mapping;
use Unifik\SystemBundle\Entity\NavigationRepository;
use Unifik\SystemBundle\Entity\SectionRepository;
use Unifik\SystemBundle\Lib\Backend\BackendController;
use Unifik\SystemBundle\Entity\Section;
use Unifik\SystemBundle\Form\Backend\RootSectionType;

/**
 * RootSection Controller
 */
class RootController extends BackendController
{
    /**
     * @var NavigationRepository
     */
    protected $navigationRepository;

    /**
     * @var SectionRepository
     */
    protected $sectionRepository;

    /**
     * @var AppRepository
     */
    protected $appRepository;

    /**
     * Init
     */
    public function init()
    {
        parent::init();

        $this->createAndPushNavigationElement('Sections', 'unifik_system_backend_section_root', array(
            'appSlug' => $this->getApp()->getSlug()
        ));

        $this->navigationRepository = $this->getEm()->getRepository('UnifikSystemBundle:Navigation');
        $this->sectionRepository = $this->getEm()->getRepository('UnifikSystemBundle:Section');
        $this->appRepository = $this->getEm()->getRepository('UnifikSystemBundle:App');
    }

    /**
     * Lists all root sections by navigation
     *
     * @return Response
     */
    public function listAction()
    {
        $navigations = $this->navigationRepository->findHaveSections($this->getApp()->getId());
        $withoutNavigation = $this->sectionRepository->findRootsWithoutNavigation($this->getApp()->getId());

        return $this->render('UnifikSystemBundle:Backend/Section/Root:list.html.twig', array(
            'navigations' => $navigations,
            'withoutNavigation' => $withoutNavigation,
            'managedApp' => $this->getApp()
        ));
    }

    /**
     * Displays a form to edit an existing Section entity or create a new one.
     *
     * @param integer $id      The id of the Section to edit
     * @param Request $request
     *
     * @return RedirectResponse|Response
     */
    public function editAction($id, Request $request)
    {
        $entity = $this->sectionRepository->find($id);

        if (false == $entity) {
            $entity = $this->initEntity(new Section());
            $entity->setApp($this->getApp());
        }

        $this->pushNavigationElement($entity);

        $form = $this->createForm(new RootSectionType(), $entity, array('current_section' => $entity, 'managed_app' => $this->getApp()));

        if ('POST' == $request->getMethod()) {

            $form->submit($request);

            if ($form->isValid()) {

                $this->getEm()->persist($entity);

                // On insert
                if (false == $id) {

                    $sectionModuleBar = $this->navigationRepository->find(NavigationRepository::SECTION_MODULE_BAR_ID);
                    $backendApp = $this->appRepository->find(1);

                    $mapping = new Mapping();
                    $mapping->setSection($entity);
                    $mapping->setApp($backendApp);
                    $mapping->setType('route');
                    $mapping->setTarget('unifik_system_backend_text');

                    $entity->addMapping($mapping);

                    $mapping = new Mapping();
                    $mapping->setSection($entity);
                    $mapping->setApp($backendApp);
                    $mapping->setNavigation($sectionModuleBar);
                    $mapping->setType('render');
                    $mapping->setTarget('UnifikSystemBundle:Backend/Text/Navigation:SectionModuleBar');

                    $entity->addMapping($mapping);

                    $mapping = new Mapping();
                    $mapping->setSection($entity);
                    $mapping->setApp($backendApp);
                    $mapping->setNavigation($sectionModuleBar);
                    $mapping->setType('render');
                    $mapping->setTarget('UnifikSystemBundle:Backend/Section/Navigation:SectionModuleBar');

                    $entity->addMapping($mapping);

                    // Frontend mapping
                    $mapping = new Mapping();
                    $mapping->setSection($entity);
                    $mapping->setApp($this->getApp());
                    $mapping->setType('route');
                    $mapping->setTarget('unifik_system_frontend_text');

                    $entity->addMapping($mapping);
                }

                $this->getEm()->flush();
                $this->get('unifik_system.router_invalidator')->invalidate();

                $this->addFlashSuccess($this->get('translator')->trans(
                    '%entity% has been saved.',
                    array('%entity%' => $entity))
                );

                if ($request->request->has('save')) {
                    return $this->redirect($this->generateUrl('unifik_system_backend_section_root', array('appSlug' => $this->getApp()->getSlug())));
                }

                return $this->redirect($this->generateUrl('unifik_system_backend_section_root_edit', array(
                    'id' => $entity->getId() ? : 0,
                    'appSlug' => $this->getApp()->getSlug()
                )));
            } else {
                $this->addFlashError('Some fields are invalid.');
            }
        }

        return $this->render('UnifikSystemBundle:Backend/Section/Root:edit.html.twig', array(
            'entity' => $entity,
            'form' => $form->createView(),
            'managedApp' => $this->getApp()
        ));
    }

    /**
     * Check if we can delete a Section.
     *
     * @param Request $request
     * @param $id
     *
     * @return JsonResponse
     */
    public function checkDeleteAction(Request $request, $id)
    {
        $section = $this->sectionRepository->find($id);
        $output = $this->checkDeleteEntity($section);

        return new JsonResponse($output);
    }

    /**
     * Deletes a Root Section entity.
     *
     * @param Request $request
     * @param integer $id      The ID of the Section to delete
     *
     * @return RedirectResponse
     */
    public function deleteAction(Request $request, $id)
    {
        $section = $this->sectionRepository->find($id);
        $this->deleteEntity($section);

        return $this->redirect($this->generateUrl('unifik_system_backend_section_root', array('appSlug' => $this->getApp()->getSlug())));
    }

    /**
     * Set order on RootSection entities.
     *
     * @return Response
     */
    public function orderAction()
    {
        if ($this->getRequest()->isXmlHttpRequest()) {

            $i = 0;
            $elements = explode(';', trim($this->getRequest()->request->get('elements'), ';'));

            // Get the navigation id
            preg_match('/_(.)*-/', $elements[0], $matches);
            $navigationId = $matches[1];

            foreach ($elements as $element) {

                $sectionId = preg_replace('/(.)*-/', '', $element);
                $entity = $this->getEm()->getRepository('UnifikSystemBundle:SectionNavigation')->findOneBy(array('section' => $sectionId, 'navigation' => $navigationId));

                if ($entity) {
                    $entity->setOrdering(++$i);
                    $this->getEm()->persist($entity);
                    $this->getEm()->flush();
                }
            }

            $this->get('unifik_system.router_invalidator')->invalidate();
        }

        return new Response('');
    }
}
