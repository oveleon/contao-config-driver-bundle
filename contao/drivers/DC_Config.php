<?php

/*
 * This file is part of Contao Config Driver Bundle.
 *
 * @package     contao-config-driver-bundle
 * @license     MIT
 * @author      Daniele Sciannimanica  <https://github.com/doishub>
 * @copyright   Oveleon                <https://www.oveleon.de/>
 */

namespace Contao;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\Image\Exception\InvalidArgumentException;
use Exception;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * Provide methods to modify data via config
 *
 * @author Daniele Sciannimanica <https://github.com/doishub>
 */
class DC_Config extends DataContainer implements ListableDataContainerInterface, EditableDataContainerInterface
{
    /**
     * Use database or local config
     */
    protected bool $useDatabase = false;

    /**
     * Database table
     */
    protected string $table;

    /**
     * Database column
     */
    protected string $column;

    /**
     * Initialize the object
     */
    public function __construct(string $strTable)
    {
        parent::__construct();

        $this->intId = Input::get('id');

        // Check whether the table is defined
        if (!$strTable || !isset($GLOBALS['TL_DCA'][$strTable]))
        {
            System::getContainer()->get('monolog.logger.contao.error')->error('Could not load data container configuration for "' . $strTable . '"');
            trigger_error('Could not load data container configuration', E_USER_ERROR);
        }

        // Build object from global configuration array
        $this->strTable = $strTable;

        // Set mode
        $this->useDatabase = !empty($GLOBALS['TL_DCA'][$this->strTable]['config']['ptable']);

        if ($this->useDatabase)
        {
            $this->table = $GLOBALS['TL_DCA'][$this->strTable]['config']['ptable'];

            if (!empty($GLOBALS['TL_DCA'][$this->strTable]['config']['configField']))
            {
                $this->column = $GLOBALS['TL_DCA'][$this->strTable]['config']['configField'];
            }
            else
            {
                System::getContainer()->get('monolog.logger.contao.error')->error('Storage via the database cannot be prepared due to missing settings: configField');
                trigger_error('Storage via the database cannot be prepared due to missing settings: configField', E_USER_ERROR);
            }
        }

        // Create fields from config
        if (!$this->generateDcaFieldsFromConfig())
        {
            return;
        }

        // Prefill on empty (only database)
        if ($this->useDatabase && !!$GLOBALS['TL_DCA'][$this->strTable]['config']['fillOnEmpty'] && empty($this->getValuesFromDatabase()))
        {
            $this->prefillConfig();
        }

        // Call onload_callback (e.g. to check permissions)
        if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['config']['onload_callback'] ?? null))
        {
            foreach ($GLOBALS['TL_DCA'][$this->strTable]['config']['onload_callback'] as $callback)
            {
                if (\is_array($callback))
                {
                    $this->import($callback[0]);
                    $this->{$callback[0]}->{$callback[1]}($this);
                }
                elseif (\is_callable($callback))
                {
                    $callback($this);
                }
            }
        }
    }

    /**
     * Adds fields to dca from config file
     */
    private function generateDcaFieldsFromConfig(): bool
    {
        // search config file
        $strFile = $GLOBALS['TL_DCA'][$this->strTable]['config']['configFile'];
        $blnMultiple = !!$GLOBALS['TL_DCA'][$this->strTable]['config']['multipleConfigFiles'];

        $arrFiles  = [];
        $arrConfig = null;

        // Always search for templates within bundles
        try
        {

            foreach (System::getContainer()->get('contao.resource_finder')->findIn('templates')->name($strFile) as $file)
            {
                /** @var SplFileInfo $file */
                $arrFiles[] = $file->getPathname();
            }
        }
        catch (InvalidArgumentException $e){}

        // Allow adding additional templates within contao / templates folder
        if (file_exists($strFilePath = System::getContainer()->getParameter('kernel.project_dir') . '/templates/' . $strFile))
        {
            $arrFiles[] = $strFilePath;
        }

        if (count($arrFiles) === 1 || (!$blnMultiple && count($arrFiles)))
        {
            if (!file_exists($arrFiles[0]))
            {
                return false;
            }

            $arrConfig = include $arrFiles[0];
        }
        elseif (count($arrFiles) && $blnMultiple)
        {
            foreach ($arrFiles as $strPath)
            {
                if (!file_exists($strPath))
                {
                    continue;
                }

                $_config = include $strPath;
                $arrConfig = $this->mergeConfig($arrConfig, $_config);
            }
        }
        else
        {
            return false;
        }

        // Set dca fields from config
        $GLOBALS['TL_DCA'][$this->strTable]['fields'] = $arrConfig['fields'];

        // Set palettes
        if (!empty($arrConfig['palettes']))
        {
            $GLOBALS['TL_DCA'][$this->strTable]['palettes'] = $arrConfig['palettes'];
        }
        else
        {
            $GLOBALS['TL_DCA'][$this->strTable]['palettes']['default'] = '{config_legend},' . implode(",", array_keys($arrConfig['fields'])) . ';';
        }

        return true;
    }

    /**
     * Merge config files
     */
    public function mergeConfig($a, $b): array
    {
        if (null === $a)
        {
            return $b;
        }

        $arrMerge = [];

        if ($b['palettes'])
        {
            foreach ($b['palettes'] as $name => $palette)
            {
                if (array_key_exists($name, $a['palettes']))
                {
                    $arrMerge['palettes'][$name] = $a['palettes'][$name] . $b['palettes'][$name];
                }
                else
                {
                    $arrMerge['palettes'][$name] = $palette;
                }
            }
        }
        else
        {
            $arrMerge['palettes'] = $a['palettes'];
        }

        if ($b['fields'])
        {
            $arrMerge['fields'] = array_merge_recursive($a['fields'], $b['fields']);
        }
        else
        {
            $arrMerge['fields'] = $a['fields'];
        }

        return $arrMerge;
    }

    /**
     * Return the values of fields from database
     */
    private function getValuesFromDatabase(): array
    {
        // Get the field values from ptable
        $objRow = $this->Database->prepare("SELECT " . $this->column . " FROM " . $this->table . " WHERE id=?")
            ->limit(1)
            ->execute($this->intId);

        return StringUtil::deserialize($objRow->{$this->column}, true);
    }

    /**
     * If the Config has never been saved before, all fields are prefilled
     */
    private function prefillConfig(): void
    {
        $arrDefaults = [];

        foreach ($GLOBALS['TL_DCA'][$this->strTable]['fields'] as $key => $field) {
            $arrDefaults[$key] = $field['default'] ?? '';
        }

        $this->updateConfig($arrDefaults);
    }

    /**
     * Updates the configuration in the database
     */
    private function updateConfig(array $arrConfig): void
    {
        $this->Database->prepare("UPDATE " . $this->table . " SET " . $this->column . "=? WHERE id=?")
            ->execute(serialize($arrConfig), $this->intId);
    }

    /**
     * Automatically switch to edit mode
     * @throws Exception
     */
    public function create(): string
    {
        return $this->edit();
    }

    /**
     * Auto-generate a form to edit the local configuration file
     *
     * @return string
     * @throws Exception
     */
    public function edit(): string
    {
        $return = '';
        $ajaxId = null;

        if (Environment::get('isAjaxRequest'))
        {
            $ajaxId = func_get_arg(1);
        }

        // Build an array from boxes and rows
        $this->strPalette = $this->getPalette();
        $boxes = StringUtil::trimsplit(';', $this->strPalette);
        $legends = [];

        if ($this->useDatabase)
        {
            $arrValues = $this->getValuesFromDatabase();
        }

        if (!empty($boxes))
        {
            foreach ($boxes as $k => $v)
            {
                $boxes[$k] = StringUtil::trimsplit(',', $v);

                foreach ($boxes[$k] as $kk => $vv)
                {
                    if (preg_match('/^\[.*]$/', $vv))
                    {
                        continue;
                    }

                    if (preg_match('/^{.*}$/', $vv))
                    {
                        $legends[$k] = substr($vv, 1, -1);
                        unset($boxes[$k][$kk]);
                    }
                    elseif (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$vv]['exclude'] ?? null) || !\is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$vv]))
                    {
                        unset($boxes[$k][$kk]);
                    }
                }

                // Unset a box if it does not contain any fields
                if (empty($boxes[$k]))
                {
                    unset($boxes[$k]);
                }
            }

            /** @var Session $objSessionBag */
            $objSessionBag = System::getContainer()->get('request_stack')->getSession()->getBag('contao_backend');

            // Render boxes
            $class = 'tl_tbox';
            $fs = $objSessionBag->get('fieldset_states');

            foreach ($boxes as $k => $v)
            {
                $strAjax = '';
                $blnAjax = false;
                $key = '';
                $cls = '';
                $legend = '';

                $version = ContaoCoreBundle::getVersion();

                if (version_compare($version, '5', '<'))
                {
                    $version = 413;
                }
                else if (version_compare($version, '5.3', '<'))
                {
                    $version = 5;
                }
                else {
                    $version = 53;
                }

                if (isset($legends[$k]))
                {
                    list($key, $cls) = explode(':', $legends[$k]) + array(null, null);

                    $legend = match ($version) {
                        53 => "\n" . '<legend data-action="click->contao--toggle-fieldset#toggle">' . ($GLOBALS['TL_LANG'][$this->strTable][$key] ?? $key) . '</legend>',
                        5 => "\n" . '<legend data-toggle-fieldset="' . StringUtil::specialcharsAttribute(json_encode(array('id' => $key, 'table' => $this->strTable))) . '">' . ($GLOBALS['TL_LANG'][$this->strTable][$key] ?? $key) . '</legend>',
                        default => "\n" . '<legend onclick="AjaxRequest.toggleFieldset(this, \'' . $key . '\', \'' . $this->strTable . '\')">' . ($GLOBALS['TL_LANG'][$this->strTable][$key] ?? $key) . '</legend>',
                    };
                }

                $collapseCls = 'hide';

                if ($legend)
                {
                    if (isset($fs[$this->strTable][$key]))
                    {
                        $class .= ($fs[$this->strTable][$key] ? '' : ' collapsed');
                    }
                    elseif ($cls)
                    {
                        // Contao 5.3 -> Convert the ":hide" suffix from the DCA
                        if (53 === $version && $cls == $collapseCls)
                        {
                            $cls = $collapseCls = 'collapsed';
                        }

                        $class .= ' ' . $cls;
                    }
                }

                // Add possibility to set custom classes into fieldset legends
                if ($cls && $collapseCls !== $cls)
                {
                    $class .= (!empty($class) ? ' ' : '') . str_replace($collapseCls . ' ', '', $cls);

                    // Add possibility to create palette group legends
                    if (str_contains($class, 'palette-group'))
                    {
                        $paletteGroup = '';
                        $this->extractPaletteGroupHeader($paletteGroup, $class);

                        if (isset($GLOBALS['TL_LANG'][$this->strTable][$paletteGroup]))
                        {
                            $return .= vsprintf("\n\n".'<div class="%s"><div class="%s">%s</div></div>', [
                                'tl_box palette-group',
                                'palette-group-header',
                                $GLOBALS['TL_LANG'][$this->strTable][$paletteGroup]
                            ]);
                        }
                    }
                }

                if (53 === $version)
                {
                    $return .= "\n\n" . '<fieldset class="' . $class . ($legend ? '' : ' nolegend') . '" data-controller="contao--toggle-fieldset" data-contao--toggle-fieldset-id-value="' . $key . '" data-contao--toggle-fieldset-table-value="' . $this->strTable . '" data-contao--toggle-fieldset-collapsed-class="collapsed" data-contao--jump-targets-target="section" data-contao--jump-targets-label-value="' . ($GLOBALS['TL_LANG'][$this->strTable][$key] ?? $key) . '" data-action="contao--jump-targets:scrollto->contao--toggle-fieldset#open">' . $legend;
                }
                else
                {
                    $return .= "\n\n" . '<fieldset' . ($key ? ' id="pal_' . $key . '"' : '') . ' class="' . $class . ($legend ? '' : ' nolegend') . '">' . $legend;
                }

                // Build rows of the current box
                foreach ($v as $vv)
                {
                    if ($vv == '[EOF]')
                    {
                        if ($blnAjax && Environment::get('isAjaxRequest'))
                        {
                            return $strAjax . '<input type="hidden" name="FORM_FIELDS[]" value="' . StringUtil::specialchars($this->strPalette) . '">';
                        }

                        $blnAjax = false;
                        $return .= "\n  " . '</div>';

                        continue;
                    }

                    if (preg_match('/^\[.*]$/', $vv))
                    {
                        $thisId = 'sub_' . substr($vv, 1, -1);
                        $blnAjax = ($ajaxId == $thisId && Environment::get('isAjaxRequest')) ? true : false;
                        $return .= "\n  " . '<div id="' . $thisId . '" class="subpal cf">';

                        continue;
                    }

                    $this->strField = $vv;
                    $this->strInputName = $vv;

                    if ($this->useDatabase)
                    {
                        $this->varValue = $arrValues[$this->strField] ?? null;
                    }
                    else
                    {
                        $this->varValue = Config::get($this->strField);
                    }

                    // Handle entities
                    if (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['inputType'] ?? null) == 'text' || ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['inputType'] ?? null) == 'textarea')
                    {
                        if ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['eval']['multiple'] ?? null)
                        {
                            $this->varValue = StringUtil::deserialize($this->varValue);
                        }
                    }

                    // Call load_callback
                    if (\is_array(($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['load_callback'] ?? null)))
                    {
                        foreach ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['load_callback'] as $callback)
                        {
                            if (\is_array($callback))
                            {
                                $this->import($callback[0]);
                                $this->varValue = $this->{$callback[0]}->{$callback[1]}($this->varValue, $this);
                            }
                            elseif (\is_callable($callback))
                            {
                                $this->varValue = $callback($this->varValue, $this);
                            }
                        }
                    }

                    // Build row
                    $blnAjax ? $strAjax .= $this->row() : $return .= $this->row();
                }

                $class = 'tl_box';
                $return .= "\n" . '</fieldset>';
            }
        }

        if (!$this->useDatabase)
        {
            $this->import('Files');

            // Check whether the target file is writeable
            if (!$this->Files->is_writeable('system/config/localconfig.php'))
            {
                Message::addError(sprintf($GLOBALS['TL_LANG']['ERR']['notWriteable'], 'system/config/localconfig.php'));
            }
        }

        // Submit buttons
        $arrButtons = [];
        $arrButtons['save'] = '<button type="submit" name="save" id="save" class="tl_submit" accesskey="s">' . $GLOBALS['TL_LANG']['MSC']['save'] . '</button>';
        $arrButtons['saveNclose'] = '<button type="submit" name="saveNclose" id="saveNclose" class="tl_submit" accesskey="c">' . $GLOBALS['TL_LANG']['MSC']['saveNclose'] . '</button>';

        // Call the buttons_callback (see #4691)
        if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['edit']['buttons_callback'] ?? null))
        {
            foreach ($GLOBALS['TL_DCA'][$this->strTable]['edit']['buttons_callback'] as $callback)
            {
                if (\is_array($callback))
                {
                    $this->import($callback[0]);
                    $arrButtons = $this->{$callback[0]}->{$callback[1]}($arrButtons, $this);
                }
                elseif (\is_callable($callback))
                {
                    $arrButtons = $callback($arrButtons, $this);
                }
            }
        }

        // Add the buttons and end the form
        $return .= '
</div>
<div class="tl_formbody_submit">
<div class="tl_submit_container">
  ' . implode(' ', $arrButtons) . '
</div>
</div>
</form>';

        // Begin the form (-> DO NOT CHANGE THIS ORDER -> this way the onsubmit attribute of the form can be changed by a field)
        $return = Message::generate() . ($this->noReload ? '
<p class="tl_error">' . $GLOBALS['TL_LANG']['ERR']['general'] . '</p>' : '') . '
<div id="tl_buttons">
<a href="' . $this->getReferer(true) . '" class="header_back" title="' . StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['backBTTitle']) . '" accesskey="b" onclick="Backend.getScrollOffset()">' . $GLOBALS['TL_LANG']['MSC']['backBT'] . '</a>
</div>
<form action="' . StringUtil::ampersand(Environment::get('request'), true) . '" id="' . $this->strTable . '" class="tl_form tl_edit_form" method="post"' . (!empty($this->onsubmit) ? ' onsubmit="' . implode(' ', $this->onsubmit) . '"' : '') . '>
<div class="tl_formbody_edit">
<input type="hidden" name="FORM_SUBMIT" value="' . $this->strTable . '">
<input type="hidden" name="REQUEST_TOKEN" value="' . htmlspecialchars(System::getContainer()->get('contao.csrf.token_manager')->getDefaultTokenValue()) . '">
<input type="hidden" name="FORM_FIELDS[]" value="' . StringUtil::specialchars($this->strPalette) . '">' . $return;

        // Reload the page to prevent _POST variables from being sent twice
        if (Input::post('FORM_SUBMIT') == $this->strTable && !$this->noReload) {
            // Call onsubmit_callback
            if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['config']['onsubmit_callback'])) {
                foreach ($GLOBALS['TL_DCA'][$this->strTable]['config']['onsubmit_callback'] as $callback) {
                    if (\is_array($callback)) {
                        $this->import($callback[0]);
                        $this->{$callback[0]}->{$callback[1]}($this);
                    } elseif (\is_callable($callback)) {
                        $callback($this);
                    }
                }
            }

            // Reload
            if (isset($_POST['saveNclose']))
            {
                Message::reset();
                System::setCookie('BE_PAGE_OFFSET', 0, 0);
                $this->redirect($this->getReferer());
            }

            $this->reload();
        }

        // Set the focus if there is an error
        if ($this->noReload)
        {
            $return .= '
<script>
  window.addEvent(\'domready\', function() {
    Backend.vScrollTo(($(\'' . $this->strTable . '\').getElement(\'label.error\').getPosition().y - 20));
  });
</script>';
        }

        return $return;
    }

    /**
     * Return the name of the current palette
     */
    public function getPalette(): string
    {
        $strPalette = $GLOBALS['TL_DCA'][$this->strTable]['palettes']['default'] ?? '';

        // Check whether there are selector fields
        if (!empty($GLOBALS['TL_DCA'][$this->strTable]['palettes']['__selector__']))
        {
            $sValues = [];
            $subpalettes = [];

            foreach ($GLOBALS['TL_DCA'][$this->strTable]['palettes']['__selector__'] as $name)
            {
                $trigger = Config::get($name);

                // Overwrite the trigger if the page is not reloaded
                if (Input::post('FORM_SUBMIT') == $this->strTable)
                {
                    $key = (Input::get('act') == 'editAll') ? $name . '_' . $this->intId : $name;

                    if (!($GLOBALS['TL_DCA'][$this->strTable]['fields'][$name]['eval']['submitOnChange'] ?? null))
                    {
                        $trigger = Input::post($key);
                    }
                }

                if ($trigger)
                {
                    if (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$name]['inputType'] ?? null) == 'checkbox' && !($GLOBALS['TL_DCA'][$this->strTable]['fields'][$name]['eval']['multiple'] ?? null))
                    {
                        $sValues[] = $name;

                        // Look for a subpalette
                        if (isset($GLOBALS['TL_DCA'][$this->strTable]['subpalettes'][$name]))
                        {
                            $subpalettes[$name] = $GLOBALS['TL_DCA'][$this->strTable]['subpalettes'][$name];
                        }
                    }
                    else
                    {
                        $sValues[] = $trigger;
                        $key = $name . '_' . $trigger;

                        // Look for a subpalette
                        if (isset($GLOBALS['TL_DCA'][$this->strTable]['subpalettes'][$key]))
                        {
                            $subpalettes[$name] = $GLOBALS['TL_DCA'][$this->strTable]['subpalettes'][$key];
                        }
                    }
                }
            }

            // Build possible palette names from the selector values
            if (empty($sValues))
            {
                $names = ['default'];
            }
            elseif (\count($sValues) > 1)
            {
                $names = $this->combiner($sValues);
            }
            else
            {
                $names = [$sValues[0]];
            }

            // Get an existing palette
            foreach ($names as $paletteName)
            {
                if (isset($GLOBALS['TL_DCA'][$this->strTable]['palettes'][$paletteName]))
                {
                    $strPalette = $GLOBALS['TL_DCA'][$this->strTable]['palettes'][$paletteName];
                    break;
                }
            }

            // Include subpalettes
            foreach ($subpalettes as $k=>$v)
            {
                $strPalette = preg_replace('/\b' . preg_quote($k, '/') . '\b/i', $k . ',[' . $k . '],' . $v . ',[EOF]', $strPalette);
            }
        }

        return $strPalette;
    }

    /**
     * Automatically switch to edit mode
     * @throws Exception
     */
    public function cut(): string
    {
        return $this->edit();
    }

    /**
     * Automatically switch to edit mode
     * @throws Exception
     */
    public function copy(): string
    {
        return $this->edit();
    }

    /**
     * Automatically switch to edit mode
     * @throws Exception
     */
    public function move(): string
    {
        return $this->edit();
    }

    /**
     * Automatically switch to edit mode
     * @throws Exception
     */
    public function undo(): string
    {
        return $this->edit();
    }

    /**
     * Automatically switch to edit mode
     * @throws Exception
     */
    public function delete(): string
    {
        return $this->edit();
    }

    /**
     * Automatically switch to edit mode
     * @throws Exception
     */
    public function show(): string
    {
        return $this->edit();
    }

    /**
     * Automatically switch to edit mode
     * @throws Exception
     */
    public function showAll(): string
    {
        return $this->edit();
    }

    /**
     * Save the current value
     */
    protected function save($varValue): void
    {
        if (Input::post('FORM_SUBMIT') != $this->strTable)
        {
            return;
        }

        $arrData = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField];

        // Make sure that checkbox values are boolean
        if (($arrData['inputType'] ?? null) == 'checkbox' && !($arrData['eval']['multiple'] ?? null))
        {
            $varValue = $varValue ? true : false;
        }

        if ($varValue)
        {
            // Convert binary UUIDs (see #6893)
            if (($arrData['inputType'] ?? null) == 'fileTree')
            {
                $varValue = StringUtil::deserialize($varValue);

                if (!\is_array($varValue)) {
                    $varValue = StringUtil::binToUuid($varValue);
                }
                else
                {
                    $varValue = serialize(array_map('\Contao\StringUtil::binToUuid', $varValue));
                }
            }

            // Convert date formats into timestamps
            if ($varValue !== null && $varValue !== '' && \in_array($arrData['eval']['rgxp'] ?? null, array('date', 'time', 'datim')))
            {
                $objDate = new Date($varValue, Date::getFormatFromRgxp($arrData['eval']['rgxp']));
                $varValue = $objDate->tstamp;
            }
        }

        // Trigger the save_callback
        if (\is_array($arrData['save_callback'] ?? null))
        {
            foreach ($arrData['save_callback'] as $callback)
            {
                if (\is_array($callback))
                {
                    $this->import($callback[0]);
                    $varValue = $this->{$callback[0]}->{$callback[1]}($varValue, $this);
                }
                elseif (\is_callable($callback))
                {
                    $varValue = $callback($varValue, $this);
                }
            }
        }

        $strCurrent = $this->varValue;

        // Handle arrays and strings
        if (\is_array($strCurrent))
        {
            $strCurrent = serialize($strCurrent);
        }
        elseif (\is_string($strCurrent))
        {
            $strCurrent = html_entity_decode($this->varValue, ENT_QUOTES, System::getContainer()->getParameter('kernel.charset'));
        }

        // Save the value if there was no error
        if ((\strlen($varValue) || !($arrData['eval']['doNotSaveEmpty'] ?? false)) && $strCurrent != $varValue)
        {
            if ($this->useDatabase)
            {
                $arrValues = $this->getValuesFromDatabase();

                $arrValues[$this->strField] = $varValue;

                $this->updateConfig($arrValues);
            }
            else
            {
                Config::persist($this->strField, $varValue);

                $deserialize = StringUtil::deserialize($varValue);
                $prior = \is_bool(Config::get($this->strField)) ? (Config::get($this->strField) ? 'true' : 'false') : Config::get($this->strField);

                // Add a log entry
                if (!\is_array($deserialize) && !\is_array(StringUtil::deserialize($prior)))
                {
                    if (($arrData['inputType'] ?? null) == 'password' || ($arrData['inputType'] ?? null) == 'textStore')
                    {
                        System::getContainer()->get('monolog.logger.contao.configuration')->info('The global configuration variable "' . $this->strField . '" has been changed');
                    }
                    else
                    {
                        System::getContainer()->get('monolog.logger.contao.configuration')->info('The global configuration variable "' . $this->strField . '" has been changed from "' . $prior . '" to "' . $varValue . '"');
                    }
                }

                // Set the new value so the input field can show it
                $this->varValue = $deserialize;
                Config::set($this->strField, $deserialize);
            }
        }
    }

    protected function extractPaletteGroupHeader(&$attribute, &$string): void
    {
        $pattern = '/palette-group\[(.*?)\]/';

        if (preg_match($pattern, $string, $matches))
        {
            $attribute = $matches[1];
            $string = rtrim(preg_replace($pattern, '', $string));
        }
    }
}
