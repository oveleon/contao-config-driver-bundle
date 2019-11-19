# Config Driver for Contao Open Source CMS

This extension adds another driver to the Contao Open Source CMS.

With the Config-Driver it is possible to load fields from a configuration file and output them in the backend. The DCA structure used by Contao is kept. It can be decided whether the data is stored in the localconfig or in an existing database column.

For the storage in an existing database column, all fields of the configuration file are stored serialized.

### Example for saving in localconfig:
```
$GLOBALS['TL_DCA']['tl_newdca'] = array
(
    'config' => array
    (
        'dataContainer'               => 'Config',
        'configFile'                  => 'dcaConfigFile' // Configuration-File: /templates/dcaConfigFile.php
    )
);
```

### Example for saving in existing database table/column:

```
$GLOBALS['TL_DCA']['tl_newdca'] = array
(
    'config' => array
    (
        'dataContainer'               => 'Config',
        'ptable'                      => 'tl_theme',                // Table
        'configField'                 => 'configData',              // Column
        'configFile'                  => 'dcaConfigFile'            // Configuration-File: /templates/dcaConfigFile.php
    )
);
```

### Example of a configuration file
```
return array(
    'palettes' => array(                                    //
        'default'  => '{font_legend},fontsize,fontcolor'    // Optional
    ),                                                      // 
    'fields'   => array(
        'fontsize' => array
        (
            'label'                   => &$GLOBALS['TL_LANG']['tl_newdca']['fontsize'],
            'inputType'               => 'inputUnit',
            'options'                 => $GLOBALS['TL_CSS_UNITS'],
            'eval'                    => array('includeBlankOption'=>true, 'rgxp'=>'digit_inherit', 'maxlength' => 20, 'tl_class'=>'w50'),
        ),
        'fontcolor' => array
        (
            'label'                   => &$GLOBALS['TL_LANG']['tl_newdca']['fontcolor'],
            'inputType'               => 'text',
            'eval'                    => array('maxlength'=>6, 'colorpicker'=>true, 'isHexColor'=>true, 'decodeEntities'=>true, 'tl_class'=>'w50 wizard'),
        )
    )
);
```

### Further Examples
To continue the example from above and to be able to call the configuration in the backend via the themes, we can add another icon for each theme.
##### config/config.php
```
$GLOBALS['BE_MOD']['design']['themes']['tables'][] = 'tl_newdca';
```

##### dca/tl_theme.php
```
// Add operation
$GLOBALS['TL_DCA']['tl_theme']['list']['operations']['newdca'] = array
(
    'label'               => &$GLOBALS['TL_LANG']['tl_theme']['newdca'],
    'href'                => 'table=tl_newdca',
    'icon'                => 'css.svg',
);

// Add fields
$GLOBALS['TL_DCA']['tl_theme']['fields']['configData'] = array
(
    'inputType'      => 'text',
    'sql'            => "text NULL"
);
```

##### Backend View
![Admin View: List](https://www.oveleon.de/share/github-assets/contao-config-driver-bundle/config-driver-example.png)

Now we can edit the configuration and use it as we like.
