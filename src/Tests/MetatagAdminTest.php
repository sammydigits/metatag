<?php

namespace Drupal\metatag\Tests;

use Drupal\simpletest\WebTestBase;

/**
 * Tests the Metatag administration.
 *
 * @group Metatag
 */
class MetatagAdminTest extends WebTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'node',
    'test_page_test',
    'token',
    'metatag',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    // Use the test page as the front page.
    $this->config('system.site')->set('page.front', '/test-page')->save();

    // Create Basic page and Article node types.
    if ($this->profile != 'standard') {
      $this->drupalCreateContentType([
        'type' => 'page',
        'name' => 'Basic page',
        'display_submitted' => FALSE,
      ]);
      $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    }
  }

  /**
   * Tests the interface to manage metatag defaults.
   */
  function testDefaults() {
    // Save the default title to test the Revert operation at the end.
    $metatag_defaults = \Drupal::config('metatag.metatag_defaults.global');
    $default_title = $metatag_defaults->get('tags')['title'];

    // Initiate session with a user who can manage metatags.
    $permissions = ['administer site configuration', 'administer meta tags'];
    $account = $this->drupalCreateUser($permissions);
    $this->drupalLogin($account);

    // Check that the user can see the list of metatag defaults.
    $this->drupalGet('admin/config/search/metatag');
    $this->assertResponse(200);

    // Check that the Global defaults were created.
    $this->assertLinkByHref('admin/config/search/metatag/global', 0, t('Global defaults were created on installation.'));

    // Check that Global and entity defaults can't be deleted.
    $this->assertNoLinkByHref('admin/config/search/metatag/global/delete', 0, t('Global defaults can\'t be deleted'));
    $this->assertNoLinkByHref('admin/config/search/metatag/node/delete', 0, t('Entity defaults can\'t be deleted'));

    // Check that the module defaults were injected into the Global config
    // entity.
    $this->drupalGet('admin/config/search/metatag/global');
    $this->assertResponse(200);
    $this->assertFieldById('edit-title', $metatag_defaults->get('title'), t('Metatag defaults were injected into the Global configuration entity.'));

    // Update the Global defaults and test them.
    $this->drupalGet('admin/config/search/metatag/global');
    $this->assertResponse(200);
    $values = [
      'title' => 'Test title',
      'description' => 'Test description',
    ];
    $this->drupalPostForm(NULL, $values, 'Save');
    $this->assertText('Saved the Global Metatag defaults.');
    $this->drupalGet('hit-a-404');
    $this->assertResponse(404);
    foreach ($values as $metatag => $value) {
      $this->assertRaw($value, t('Updated metatag @tag was found in the HEAD section of the page.', ['@tag' => $metatag]));
    }

    // Check that tokens are processed.
    $this->drupalGet('admin/config/search/metatag/global');
    $this->assertResponse(200);
    $values = [
      'title' => '[site:name] | Test title',
      'description' => '[site:name] | Test description',
    ];
    $this->drupalPostForm(NULL, $values, 'Save');
    $this->assertText('Saved the Global Metatag defaults.');
    drupal_flush_all_caches();
    $this->drupalGet('hit-a-404');
    $this->assertResponse(404);
    foreach ($values as $metatag => $value) {
      $processed_value = \Drupal::token()->replace($value);
      $this->assertRaw($processed_value, t('Processed token for metatag @tag was found in the HEAD section of the page.', ['@tag' => $metatag]));
    }

    // Test the Robots plugin.
    $this->drupalGet('admin/config/search/metatag/global');
    $this->assertResponse(200);
    $robots_values = ['index', 'follow', 'noydir'];
    $values = [];
    foreach ($robots_values as $value) {
      $values['robots[' . $value . ']'] = TRUE;
    }
    $this->drupalPostForm(NULL, $values, 'Save');
    $this->assertText('Saved the Global Metatag defaults.');
    drupal_flush_all_caches();

    // Trigger a 404 request.
    $this->drupalGet('hit-a-404');
    $this->assertResponse(404);
    $robots_value = implode(', ', $robots_values);
    $this->assertRaw($robots_value, t('Robots metatag has the expected values.'));

    // Test reverting global configuration to its defaults.
    $this->drupalGet('admin/config/search/metatag/global/revert');
    $this->assertResponse(200);
    $this->drupalPostForm(NULL, [], 'Revert');
    $this->assertText('Reverted Global defaults.');
    $this->assertText($default_title, 'Global title was reverted to its default value.');

    $this->drupalLogout();
  }

  /**
   * Tests special pages.
   */
  function testSpecialPages() {
    // Initiate session with a user who can manage metatags.
    $permissions = ['administer site configuration', 'administer meta tags'];
    $account = $this->drupalCreateUser($permissions);
    $this->drupalLogin($account);

    // Adjust the front page and test it.
    $this->drupalGet('admin/config/search/metatag/front');
    $this->assertResponse(200);
    $values = [
      'description' => 'Front page description',
    ];
    $this->drupalPostForm(NULL, $values, 'Save');
    $this->assertText('Saved the Front page Metatag defaults.');
    $this->drupalGet('<front>');
    $this->assertResponse(200);
    $this->assertRaw($values['description'], t('Front page defaults are used at the front page.'));

    // Adjust the 403 page and test it.
    $this->drupalGet('admin/config/search/metatag/403');
    $this->assertResponse(200);
    $values = [
      'description' => '403 page description.',
    ];
    $this->drupalPostForm(NULL, $values, 'Save');
    $this->assertText('Saved the 403 access denied Metatag defaults.');
    $this->drupalLogout();
    $this->drupalGet('admin/config/search/metatag');
    $this->assertResponse(403);
    $this->assertRaw($values['description'], t('403 page defaults are used at 403 pages.'));

    // Adjust the 404 page and test it.
    $this->drupalLogin($account);
    $this->drupalGet('admin/config/search/metatag/404');
    $this->assertResponse(200);
    $values = [
      'description' => '404 page description.',
    ];
    $this->drupalPostForm(NULL, $values, 'Save');
    $this->assertText('Saved the 404 page not found Metatag defaults.');
    $this->drupalGet('foo');
    $this->assertResponse(404);
    $this->assertRaw($values['description'], t('404 page defaults are used at 404 pages.'));
    $this->drupalLogout();
  }

  /**
   * Tests entity and bundle overrides.
   */
  function testOverrides() {
    // Initiate session with a user who can manage metatags.
    $permissions = ['administer site configuration', 'administer meta tags', 'access content', 'create article content', 'administer nodes', 'create article content', 'create page content'];
    $account = $this->drupalCreateUser($permissions);
    $this->drupalLogin($account);

    // Update the Metatag Node defaults.
    $this->drupalGet('admin/config/search/metatag/node');
    $this->assertResponse(200);
    $values = [
      'title' => 'Test title for a node.',
      'description' => 'Test description for a node.',
    ];
    $this->drupalPostForm(NULL, $values, 'Save');
    $this->assertText('Saved the Content Metatag defaults.');

    // Create a test node.
    $node = $this->drupalCreateNode([
      'title' => t('Hello, world!'),
      'type' => 'article',
    ]);

    // Check that the new values are found in the response.
    $this->drupalGet('node/' . $node->id());
    $this->assertResponse(200);
    foreach ($values as $metatag => $value) {
      $this->assertRaw($value, t('Node metatag @tag overrides Global defaults.', ['@tag' => $metatag]));
    }

    /**
     * Check that when the node defaults don't define a metatag, the Global one
     * is used.
     */
    // First unset node defaults.
    $this->drupalGet('admin/config/search/metatag/node');
    $this->assertResponse(200);
    $values = [
      'title' => '',
      'description' => '',
    ];
    $this->drupalPostForm(NULL, $values, 'Save');
    $this->assertText('Saved the Content Metatag defaults.');

    // Then, set global ones.
    $this->drupalGet('admin/config/search/metatag/global');
    $this->assertResponse(200);
    $values = [
      'title' => 'Global title',
      'description' => 'Global description',
    ];
    $this->drupalPostForm(NULL, $values, 'Save');
    $this->assertText('Saved the Global Metatag defaults.');

    // Next, test that global defaults are rendered since node ones are empty.
    // We are creating a new node as doing a get on the previous one would
    // return cached results.
    // @todo BookTest.php resets the cache of a single node, which is way more
    // performant than creating a node for every set of assertions.
    // @see BookTest::testDelete().
    $node = $this->drupalCreateNode([
      'title' => t('Hello, world!'),
      'type' => 'article',
    ]);
    $this->drupalGet('node/' . $node->id());
    $this->assertResponse(200);
    foreach ($values as $metatag => $value) {
      $this->assertRaw($value, t('Found global @tag tag as Node does not set it.', ['@tag' => $metatag]));
    }

    // Now create article overrides and then test them.
    $this->drupalGet('admin/config/search/metatag/add');
    $this->assertResponse(200);
    $values = [
      'id' => 'node__article',
      'title' => 'Article title override',
      'description' => 'Article description override',
    ];
    $this->drupalPostForm(NULL, $values, 'Save');
    $this->assertText('Created the Content: Article Metatag defaults.');
    $node = $this->drupalCreateNode([
      'title' => t('Hello, world!'),
      'type' => 'article',
    ]);
    $this->drupalGet('node/' . $node->id());
    $this->assertResponse(200);
    unset($values['id']);
    foreach ($values as $metatag => $value) {
      $this->assertRaw($value, t('Found bundle override for tag @tag.', ['@tag' => $metatag]));
    }

    // Test deleting the article defaults.
    $this->drupalGet('admin/config/search/metatag/node__article/delete');
    $this->assertResponse(200);
    $this->drupalPostForm(NULL, [], 'Delete');
    $this->assertText(t('Deleted @label defaults.', ['@label' => 'Content: Article']));
  }

}
