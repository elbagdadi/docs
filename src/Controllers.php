<?php

namespace Bolt\Docs;

use Silex;
use Silex\Api\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class Controllers implements ControllerProviderInterface
{
    /** @var Silex\Application */
    private $app;

    public function connect(Silex\Application $app)
    {
        $this->app = $app;

        /** @var $ctr \Silex\ControllerCollection */
        $ctr = $app['controllers_factory'];

        $ctr->get("/", array($this, 'home'))
            ->bind('home');

        $ctr->get("/tree/{version}.json", array($this, 'tree'))
            ->bind('tree');

        $ctr->get("/{version}/class-reference", array($this, 'classReference'))
            ->bind('classReference');

        $ctr->get("/{version}/cheatsheet", array($this, 'cheatsheet'))
            ->bind('cheatsheet');

        $ctr->get("/{version}/{slug}", array($this, 'page'))
            ->bind('page')
            ->assert('slug', '.+');

        $ctr->before(array($this, 'before'));

        $app->error(function(\Exception $e, Request $request, $code) use ($app) {
            return $this->error($e, $request, $app, $code);
        });

        return $ctr;

    }

    /**
     * Controller for homepage
     *
     * @return RedirectResponse
     */
    public function home()
    {
        return new RedirectResponse($this->app['config']['start-page']);
    }

    /**
     * Controller for ajaxy fetching the menu tree
     *
     * @param string $version
     *
     * @return Response
     */
    public function tree($version)
    {
        $contentGetter = new ContentGetter($version);

        $menu = $contentGetter->getJsonMenu('menu_docs.yml');

        $response = new Response(json_encode($menu, JSON_PRETTY_PRINT), 201);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Content-Type', 'application/vnd.api+json');

        return $response;
    }

    /**
     * Controller for pages
     *
     * @param string $version
     * @param string $slug
     *
     * @return string
     */
    public function page($version, $slug)
    {
        $contentGetter = new ContentGetter($version, $slug);

        $source = $contentGetter->source();

        if (empty($source)) {
            throw new NotFoundHttpException('Page does not exist.');
        }

        $twigVars = [
            'title'   => $contentGetter->getTitle(),
            'source'  => $source,
            'menu'    => $contentGetter->getMenu('menu_docs.yml'),
            'submenu' => $contentGetter->getSubmenu(),
            'current' => $slug,
            'version' => $version,
        ];

        return $this->render('index.twig', $twigVars);
    }

    /**
     * Controller for the class reference page
     *
     * @param string $version
     *
     * @return mixed
     */
    public function classReference($version)
    {
        $contentGetter = new ContentGetter($version);

        $cr = $contentGetter->getClassReference();

        $twigVars = [
            'title'   => 'Bolt Class Reference',
            'menu'    => $contentGetter->getMenu('menu_docs.yml'),
            'version' => $version,
            'classes' => $cr
        ];

        return $this->render('classreference.twig', $twigVars);
    }

    /**
     * Controller for the cheatsheet reference page
     *
     * @param string $version
     *
     * @return mixed
     */
    public function cheatsheet($version)
    {
        $contentGetter = new ContentGetter($version);

        $cheatsheet = $contentGetter->getCheatsheet();

        $twigVars = [
            'title'      => 'Bolt Cheatsheet',
            'menu'       => $contentGetter->getMenu('menu_docs.yml'),
            'version'    => $version,
            'cheatsheet' => $cheatsheet,
            'slug'       => 'cheatsheet'
        ];

        return $this->render('cheatsheet.twig', $twigVars);
    }

    /**
     * Middleware function to do some tasks that should be done for all requests.
     *
     * @param Request     $request
     * @param Application $app
     */
    public function before(Request $request, Application $app)
    {
        // Fetch the available versions.
        $contentGetter = new ContentGetter();

        $versions = $contentGetter->getVersions();

        if ($app['config']['debug'] === true) {
            $versions['local'] = 'local';
        }

        $app['twig']->addGlobal('config', $app['config']);
        $app['twig']->addGlobal('versions', $versions);
    }

    protected function render($template, array $variables = [])
    {
        return $this->app['twig']->render($template, $variables);
    }

    /**
     * Controller for error pages.
     *
     * @param \Exception        $e
     * @param Request           $request
     * @param Silex\Application $app
     * @param integer           $code
     *
     * @return Response|void
     */
    public function error(\Exception $e, Request $request, Silex\Application $app, $code)
    {
        $requestUri = explode('/', $request->getRequestUri());

        // Don't trap Symfony shizzle.
        if (in_array($requestUri[1], ['a', '_profiler']) || $app['debug']) {
            return;
        }

        // Fetch the available versions.
        $currentVersion = $app['config']['default-version'];
        $contentGetter = new ContentGetter($currentVersion);
        $versions = array_flip($contentGetter->getVersions());


        // Pad the versions array with 'local', because it's always there, even if we don't advertise it.
        $versions['local'] = 'local';

        // If the request didn't start with something that looks like a version,
        // redirect to the current version, only with the version prefixed.
        if (!isset($versions[ $requestUri[1] ])) {
            $redirect = $app->redirect('/' . $currentVersion . $request->getRequestUri());
            return $redirect;
        }

        // If we have a 404 error, show the 404 page.
        if ($code == 404) {
            $twigVars = [
                'title'   => "404 - Page not found",
                'source'  => "<h1>404 - Page not found</h1>" .
                             "This page could not be found. Please click one of the menu items in the " .
                             "sidebar, or use the search form to look for a specific keyword.",
                'menu'    => $contentGetter->getMenu('menu_docs.yml'),
                'version' => $currentVersion,
            ];
            $html = $app['twig']->render('index.twig', $twigVars);

            return $html;
        }

        // Otherwise, we return, and let Silex handle it.
        return;
    }

}