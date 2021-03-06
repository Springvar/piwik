<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik_Plugins
 * @package ExamplePlugin
 */
namespace Piwik\Plugins\ExamplePlugin;

use Piwik\View;

/**
 *
 * @package ExamplePlugin
 */
class Controller extends \Piwik\Plugin\Controller
{

    public function index()
    {
        $view = new View('@ExamplePlugin/index.twig');
        $this->setBasicVariablesView($view);
        $view->answerToLife = '42';

        echo $view->render();
    }
}
