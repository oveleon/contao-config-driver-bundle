# Config Driver for Contao Open Source CMS

This extension adds another driver to the Contao Open Source CMS.

With the Config-Driver it is possible to load fields from a configuration file and output them in the backend. The DCA structure used by Contao is kept. It can be decided whether the data is stored in the localconfig or in an existing database column.

Configuration files located on `ROOT` under `/templates/` are used. If no template is found, installed bundles are searched afterwards. This gives you the possibility to overwrite configuration files without discarding the default.

### Why?
In our case we need a DCA in which we have the possibility to store the fields in another database column to save database queries and performance.

Additionally we want to provide the possibility to deliver a standard configuration which can be extended via the backend. The driver was developed to deliver a basic theme and its SCSS variables from a bundle and let it be consumed or extended by another. For more information you can have a look at our bundle "[Oveleon Theme-Manager](https://github.com/oveleon/contao-oveleon-theme-manager-bundle)", in which the driver is used.

### Example for saving in localconfig:
For the storage in the `localconfig`, only two fields are needed.
#### Fields
- `dataContainer`: The driver to be used
- `configFile`: The configuration file to be used (DCA Palette and Fields)

```php
$GLOBALS['TL_DCA']['tl_newdca'] = array
(
    'config' => array
    (
        'dataContainer'               => 'Config',
        'configFile'                  => 'dcaConfigFile.html5' // Use the extension html5 to make the configuration extensible in the backend. If the configuration must not be changed, choose the extension PHP.
    )
);
```

### Example for saving in existing database table/column:
For the storage in an existing database column, all fields of the configuration file are stored serialized.
#### Fields
- `dataContainer`: The driver to be used
- `ptable`: The table in which the data is to be stored
- `configField`: The table column in which the data is stored serialized
- `configFile`: The configuration file to be used (DCA Palette and Fields)
- `multipleConfigFiles`: Merge all configFiles with same name
```php
$GLOBALS['TL_DCA']['tl_newdca'] = array
(
    'config' => array
    (
        'dataContainer'               => 'Config',
        'ptable'                      => 'tl_theme', 
        'configField'                 => 'configData',
        'configFile'                  => 'dcaConfigFile.html5',
        'multipleConfigFiles'         => true
    )
);
```

### Example of a configuration file
```php
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
To continue the example from above and to be able to access the configuration in the backend via e.g. the theme, we can add another icon for each theme.
##### config/config.php
```php
$GLOBALS['BE_MOD']['design']['themes']['tables'][] = 'tl_newdca';
```

##### dca/tl_theme.php
```php
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
Now we have the possibility to fill in all fields from the configuration for each theme and save each in its new database column.

##### Backend View
![Admin View: List](https://www.oveleon.de/share/github-assets/contao-config-driver-bundle/config-driver-example.png)
