<?php

namespace Drupal\Tests\ckeditor\Functional;

use Drupal\editor\Entity\Editor;
use Drupal\filter\Entity\FilterFormat;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests loading of CKEditor.
 *
 * @group ckeditor
 */
class CKEditorLoadingTest extends BrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['filter', 'editor', 'ckeditor', 'node'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * An untrusted user with access to only the 'plain_text' format.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $untrustedUser;

  /**
   * A normal user with access to the 'plain_text' and 'filtered_html' formats.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $normalUser;

  protected function setUp(): void {
    parent::setUp();

    // Create text format, associate CKEditor.
    $filtered_html_format = FilterFormat::create([
      'format' => 'filtered_html',
      'name' => 'Filtered HTML',
      'weight' => 0,
      'filters' => [],
    ]);
    $filtered_html_format->save();
    $editor = Editor::create([
      'format' => 'filtered_html',
      'editor' => 'ckeditor',
    ]);
    $editor->save();

    // Create a second format without an associated editor so a drop down select
    // list is created when selecting formats.
    $full_html_format = FilterFormat::create([
      'format' => 'full_html',
      'name' => 'Full HTML',
      'weight' => 1,
      'filters' => [],
    ]);
    $full_html_format->save();

    // Create node type.
    $this->drupalCreateContentType([
      'type' => 'article',
      'name' => 'Article',
    ]);

    $this->untrustedUser = $this->drupalCreateUser([
      'create article content',
      'edit any article content',
    ]);
    $this->normalUser = $this->drupalCreateUser([
      'create article content',
      'edit any article content',
      'use text format filtered_html',
      'use text format full_html',
    ]);
  }

  /**
   * Tests loading of CKEditor CSS, JS and JS settings.
   */
  public function testLoading() {
    // The untrusted user:
    // - has access to 1 text format (plain_text);
    // - doesn't have access to the filtered_html text format, so: no text editor.
    $this->drupalLogin($this->untrustedUser);
    $this->drupalGet('node/add/article');
    list($settings, $editor_settings_present, $editor_js_present, $body) = $this->getThingsToCheck();
    $this->assertFalse($editor_settings_present, 'No Text Editor module settings.');
    $this->assertFalse($editor_js_present, 'No Text Editor JavaScript.');
    $this->assertCount(1, $body, 'A body field exists.');
    $this->assertSession()->elementNotExists('css', 'select.js-filter-list');
    // Verify that a single text format hidden input does not exist on the page.
    $this->assertSession()->elementNotExists('xpath', '//input[@type="hidden" and contains(@class, "editor")]');
    // Verify that CKEditor glue JS is absent.
    $this->assertNoRaw(drupal_get_path('module', 'ckeditor') . '/js/ckeditor.js');

    // On pages where there would never be a text editor, CKEditor JS is absent.
    $this->drupalGet('user');
    $this->assertNoRaw(drupal_get_path('module', 'ckeditor') . '/js/ckeditor.js');

    // The normal user:
    // - has access to 2 text formats;
    // - does have access to the filtered_html text format, so: CKEditor.
    $this->drupalLogin($this->normalUser);
    $this->drupalGet('node/add/article');
    list($settings, $editor_settings_present, $editor_js_present, $body) = $this->getThingsToCheck();
    $ckeditor_plugin = $this->container->get('plugin.manager.editor')->createInstance('ckeditor');
    $editor = Editor::load('filtered_html');
    $expected = [
      'formats' => [
        'filtered_html' => [
          'format' => 'filtered_html',
          'editor' => 'ckeditor',
          'editorSettings' => $ckeditor_plugin->getJSSettings($editor),
          'editorSupportsContentFiltering' => TRUE,
          'isXssSafe' => FALSE,
        ],
      ],
    ];
    $this->assertTrue($editor_settings_present, "Text Editor module's JavaScript settings are on the page.");
    $this->assertEquals($expected, $settings['editor'], "Text Editor module's JavaScript settings on the page are correct.");
    $this->assertTrue($editor_js_present, 'Text Editor JavaScript is present.');
    $this->assertCount(1, $body, 'A body field exists.');
    // Verify that a single text format selector exists on the page and has a
    // "data-editor-for" attribute with the correct value.
    $this->assertSession()->elementsCount('css', 'select.js-filter-list', 1);
    $select = $this->assertSession()->elementExists('css', 'select.js-filter-list');
    $this->assertSame('edit-body-0-value', $select->getAttribute('data-editor-for'));
    $this->assertContains('ckeditor/drupal.ckeditor', explode(',', $settings['ajaxPageState']['libraries']), 'CKEditor glue library is present.');

    // Enable the ckeditor_test module, customize configuration. In this case,
    // there is additional CSS and JS to be loaded.
    // NOTE: the tests in CKEditorTest already ensure that changing the
    // configuration also results in modified CKEditor configuration, so we
    // don't test that here.
    \Drupal::service('module_installer')->install(['ckeditor_test']);
    $this->container->get('plugin.manager.ckeditor.plugin')->clearCachedDefinitions();
    $editor_settings = $editor->getSettings();
    $editor_settings['toolbar']['rows'][0][0]['items'][] = 'Llama';
    $editor->setSettings($editor_settings);
    $editor->save();
    $this->drupalGet('node/add/article');
    list($settings, $editor_settings_present, $editor_js_present, $body) = $this->getThingsToCheck();
    $expected = [
      'formats' => [
        'filtered_html' => [
          'format' => 'filtered_html',
          'editor' => 'ckeditor',
          'editorSettings' => $ckeditor_plugin->getJSSettings($editor),
          'editorSupportsContentFiltering' => TRUE,
          'isXssSafe' => FALSE,
        ],
      ],
    ];
    $this->assertTrue($editor_settings_present, "Text Editor module's JavaScript settings are on the page.");
    $this->assertEquals($expected, $settings['editor'], "Text Editor module's JavaScript settings on the page are correct.");
    $this->assertTrue($editor_js_present, 'Text Editor JavaScript is present.');
    $this->assertContains('ckeditor/drupal.ckeditor', explode(',', $settings['ajaxPageState']['libraries']), 'CKEditor glue library is present.');

    // Assert that CKEditor uses Drupal's cache-busting query string by
    // comparing the setting sent with the page with the current query string.
    $settings = $this->getDrupalSettings();
    $expected = $settings['ckeditor']['timestamp'];
    $this->assertSame($expected, \Drupal::state()->get('system.css_js_query_string'), "CKEditor scripts cache-busting string is correct before flushing all caches.");
    // Flush all caches then make sure that $settings['ckeditor']['timestamp']
    // still matches.
    drupal_flush_all_caches();
    $this->assertSame($expected, \Drupal::state()->get('system.css_js_query_string'), "CKEditor scripts cache-busting string is correct after flushing all caches.");
  }

  /**
   * Tests presence of essential configuration even without Internal's buttons.
   */
  public function testLoadingWithoutInternalButtons() {
    // Change the CKEditor text editor configuration to only have link buttons.
    // This means:
    // - 0 buttons are from \Drupal\ckeditor\Plugin\CKEditorPlugin\Internal
    // - 2 buttons are from \Drupal\ckeditor\Plugin\CKEditorPlugin\DrupalLink
    $filtered_html_editor = Editor::load('filtered_html');
    $settings = $filtered_html_editor->getSettings();
    $settings['toolbar']['rows'] = [
      0 => [
        0 => [
          'name' => 'Links',
          'items' => [
            'DrupalLink',
            'DrupalUnlink',
          ],
        ],
      ],
    ];
    $filtered_html_editor->setSettings($settings)->save();

    // Even when no buttons of \Drupal\ckeditor\Plugin\CKEditorPlugin\Internal
    // are in use, its configuration (Internal::getConfig()) is still essential:
    // this is configuration that is associated with the (custom, optimized)
    // build of CKEditor that Drupal core ships with. For example, it configures
    // CKEditor to not perform its default action of loading a config.js file,
    // to not convert special characters into HTML entities, and the allowedContent
    // setting to configure CKEditor's Advanced Content Filter.
    $this->drupalLogin($this->normalUser);
    $this->drupalGet('node/add/article');
    $editor_settings = $this->getDrupalSettings()['editor']['formats']['filtered_html']['editorSettings'];
    $this->assertTrue(isset($editor_settings['customConfig']));
    $this->assertTrue(isset($editor_settings['entities']));
    $this->assertTrue(isset($editor_settings['allowedContent']));
    $this->assertTrue(isset($editor_settings['disallowedContent']));
  }

  /**
   * Tests loading of theme's CKEditor stylesheets defined in the .info file.
   */
  public function testExternalStylesheets() {
    /** @var \Drupal\Core\Extension\ThemeInstallerInterface $theme_installer */
    $theme_installer = \Drupal::service('theme_installer');
    // Case 1: Install theme which has an absolute external CSS URL.
    $theme_installer->install(['test_ckeditor_stylesheets_external']);
    $this->config('system.theme')->set('default', 'test_ckeditor_stylesheets_external')->save();
    $expected = [
      'https://fonts.googleapis.com/css?family=Open+Sans',
    ];
    $this->assertSame($expected, _ckeditor_theme_css('test_ckeditor_stylesheets_external'));

    // Case 2: Install theme which has an external protocol-relative CSS URL.
    $theme_installer->install(['test_ckeditor_stylesheets_protocol_relative']);
    $this->config('system.theme')->set('default', 'test_ckeditor_stylesheets_protocol_relative')->save();
    $expected = [
      '//fonts.googleapis.com/css?family=Open+Sans',
    ];
    $this->assertSame($expected, _ckeditor_theme_css('test_ckeditor_stylesheets_protocol_relative'));

    // Case 3: Install theme which has a relative CSS URL.
    $theme_installer->install(['test_ckeditor_stylesheets_relative']);
    $this->config('system.theme')->set('default', 'test_ckeditor_stylesheets_relative')->save();
    $expected = [
      'core/modules/system/tests/themes/test_ckeditor_stylesheets_relative/css/yokotsoko.css',
    ];
    $this->assertSame($expected, _ckeditor_theme_css('test_ckeditor_stylesheets_relative'));

    // Case 4: Install theme which has a Drupal root CSS URL.
    $theme_installer->install(['test_ckeditor_stylesheets_drupal_root']);
    $this->config('system.theme')->set('default', 'test_ckeditor_stylesheets_drupal_root')->save();
    $expected = [
      'core/modules/system/tests/themes/test_ckeditor_stylesheets_drupal_root/css/yokotsoko.css',
    ];
    $this->assertSame($expected, _ckeditor_theme_css('test_ckeditor_stylesheets_drupal_root'));
  }

  protected function getThingsToCheck() {
    $settings = $this->getDrupalSettings();
    return [
      // JavaScript settings.
      $settings,
      // Editor.module's JS settings present.
      isset($settings['editor']),
      // Editor.module's JS present. Note: ckeditor/drupal.ckeditor depends on
      // editor/drupal.editor, hence presence of the former implies presence of
      // the latter.
      isset($settings['ajaxPageState']['libraries']) && in_array('ckeditor/drupal.ckeditor', explode(',', $settings['ajaxPageState']['libraries'])),
      // Body field.
      $this->xpath('//textarea[@id="edit-body-0-value"]'),
    ];
  }

}
