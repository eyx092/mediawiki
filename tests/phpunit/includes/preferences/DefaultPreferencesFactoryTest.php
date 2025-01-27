<?php

use MediaWiki\Auth\AuthManager;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Languages\LanguageNameUtils;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Preferences\DefaultPreferencesFactory;
use MediaWiki\Tests\Unit\DummyServicesTrait;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserOptionsLookup;
use Wikimedia\TestingAccessWrapper;

/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

/**
 * @group Preferences
 * @coversDefaultClass MediaWiki\Preferences\DefaultPreferencesFactory
 */
class DefaultPreferencesFactoryTest extends \MediaWikiIntegrationTestCase {
	use DummyServicesTrait;
	use TestAllServiceOptionsUsed;

	/** @var IContextSource */
	protected $context;

	/** @var Config */
	protected $config;

	protected function setUp() : void {
		parent::setUp();
		$this->context = new RequestContext();
		$this->context->setTitle( Title::newFromText( self::class ) );

		$services = MediaWikiServices::getInstance();

		$this->setMwGlobals( 'wgParser', $services->getParserFactory()->create() );
		$this->setMwGlobals( 'wgDisableLangConversion', false );
		$this->config = $services->getMainConfig();
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstruct() {
		// Make sure if no UserGroupManager is provided, stuff still works, so that
		// the GlobalPreferences extension isn't broken
		$params = [
			$this->createMock( ServiceOptions::class ),
			$this->createMock( Language::class ),
			$this->createMock( AuthManager::class ),
			$this->createMock( LinkRenderer::class ),
			$this->createMock( NamespaceInfo::class ),
			$this->createMock( PermissionManager::class ),
			$this->createMock( ILanguageConverter::class ),
			$this->createMock( LanguageNameUtils::class ),
			$this->createMock( HookContainer::class ),
			$this->createMock( UserOptionsLookup::class ),
		];
		$preferencesFactory = new DefaultPreferencesFactory( ...$params );
		$this->assertInstanceOf(
			DefaultPreferencesFactory::class,
			$preferencesFactory,
			'Created without a UserGroupManager'
		);

		// Now, make sure that MediaWikiServices isn't used
		$params[] = $this->createMock( UserGroupManager::class );
		$oldMwServices = MediaWikiServices::forceGlobalInstance(
			$this->createNoOpMock( MediaWikiServices::class )
		);
		// Wrap in a try-finally block to make sure the real MediaWikiServices is
		// always put back even if something goes wrong
		try {
			$preferencesFactory = new DefaultPreferencesFactory( ...$params );
			$this->assertInstanceOf(
				DefaultPreferencesFactory::class,
				$preferencesFactory,
				'Created with a UserGroupManager, MediaWikiServices not used'
			);
		} finally {
			// Put back the real MediaWikiServices
			MediaWikiServices::forceGlobalInstance( $oldMwServices );
		}
	}

	/**
	 * Get a basic PreferencesFactory for testing with.
	 * @param array $options Supported options are:
	 *    'language' - A Language object, falls back to `new Language()`
	 *    'userOptionsLookup' - A UserOptionsLookup service, falls back to using MediaWikiServices
	 *    'userGroupManager' - A UserGroupManager service, falls back to a mock where no users
	 *                         have any extra groups, just `*` and `user`
	 * @return DefaultPreferencesFactory
	 */
	protected function getPreferencesFactory( array $options = [] ) {
		// DummyServicesTrait::getDummyNamespaceInfo
		$nsInfo = $this->getDummyNamespaceInfo();

		$services = MediaWikiServices::getInstance();

		// The PermissionManager should not be used for anything, its only a parameter
		// until we figure out how to remove it without breaking the GlobalPreferences
		// extension (GlobalPreferencesFactory extends DefaultPreferencesFactory)
		$permissionManager = $this->createNoOpMock( PermissionManager::class );

		$language = $options['language'] ?? new Language();
		$userOptionsLookup = $options['userOptionsLookup'] ?? $services->getUserOptionsLookup();

		$userGroupManager = $options['userGroupManager'] ?? false;
		if ( !$userGroupManager ) {
			$userGroupManager = $this->createMock( UserGroupManager::class );
			$userGroupManager->method( 'getUserGroupMemberships' )->willReturn( [] );
			$userGroupManager->method( 'getUserEffectiveGroups' )->willReturnCallback(
				static function ( UserIdentity $user ) {
					return $user->isRegistered() ? [ '*', 'user' ] : [ '*' ];
				}
			);
		}

		return new DefaultPreferencesFactory(
			new LoggedServiceOptions( self::$serviceOptionsAccessLog,
				DefaultPreferencesFactory::CONSTRUCTOR_OPTIONS, $this->config ),
			$language,
			$services->getAuthManager(),
			$services->getLinkRenderer(),
			$nsInfo,
			$permissionManager,
			$services->getLanguageConverterFactory()->getLanguageConverter( $language ),
			$services->getLanguageNameUtils(),
			$services->getHookContainer(),
			$userOptionsLookup,
			$userGroupManager
		);
	}

	/**
	 * @covers ::getForm
	 * @covers ::searchPreferences
	 */
	public function testGetForm() {
		$this->setTemporaryHook( 'GetPreferences', null );

		$testUser = $this->getTestUser();
		$prefFactory = $this->getPreferencesFactory();
		$form = $prefFactory->getForm( $testUser->getUser(), $this->context );
		$this->assertInstanceOf( PreferencesFormOOUI::class, $form );
		$this->assertCount( 5, $form->getPreferenceSections() );
	}

	/**
	 * CSS classes for emailauthentication preference field when there's no email.
	 * @see https://phabricator.wikimedia.org/T36302
	 *
	 * @covers ::profilePreferences
	 * @dataProvider emailAuthenticationProvider
	 */
	public function testEmailAuthentication( $user, $cssClass ) {
		$prefs = $this->getPreferencesFactory()
			->getFormDescriptor( $user, $this->context );
		$this->assertArrayHasKey( 'cssclass', $prefs['emailauthentication'] );
		$this->assertEquals( $cssClass, $prefs['emailauthentication']['cssclass'] );
	}

	/**
	 * @covers ::renderingPreferences
	 */
	public function testShowRollbackConfIsHiddenForUsersWithoutRollbackRights() {
		$userMock = $this->getMockBuilder( User::class )
			->disableOriginalConstructor()
			->getMock();
		$userMock->method( 'isAllowed' )->willReturnCallback(
			static function ( $permission ) {
				return $permission === 'editmyoptions';
			}
		);

		$userOptionsLookupMock = $this->createUserOptionsLookupMock( [ 'test' => 'yes' ], true );
		$prefs = $this->getPreferencesFactory( [
			'userOptionsLookup' => $userOptionsLookupMock,
		] )->getFormDescriptor( $userMock, $this->context );
		$this->assertArrayNotHasKey( 'showrollbackconfirmation', $prefs );
	}

	/**
	 * @covers ::renderingPreferences
	 */
	public function testShowRollbackConfIsShownForUsersWithRollbackRights() {
		$userMock = $this->getMockBuilder( User::class )
			->disableOriginalConstructor()
			->getMock();
		$userMock->method( 'isAllowed' )->willReturnCallback(
			static function ( $permission ) {
				return $permission === 'editmyoptions' || $permission === 'rollback';
			}
		);

		$userOptionsLookupMock = $this->createUserOptionsLookupMock( [ 'test' => 'yes' ], true );
		$prefs = $this->getPreferencesFactory( [
			'userOptionsLooku' => $userOptionsLookupMock,
		] )->getFormDescriptor( $userMock, $this->context );
		$this->assertArrayHasKey( 'showrollbackconfirmation', $prefs );
		$this->assertEquals(
			'rendering/advancedrendering',
			$prefs['showrollbackconfirmation']['section']
		);
	}

	public function emailAuthenticationProvider() {
		$userNoEmail = new User;
		$userEmailUnauthed = new User;
		$userEmailUnauthed->setEmail( 'noauth@example.org' );
		$userEmailAuthed = new User;
		$userEmailAuthed->setEmail( 'noauth@example.org' );
		$userEmailAuthed->setEmailAuthenticationTimestamp( wfTimestamp() );
		return [
			[ $userNoEmail, 'mw-email-none' ],
			[ $userEmailUnauthed, 'mw-email-not-authenticated' ],
			[ $userEmailAuthed, 'mw-email-authenticated' ],
		];
	}

	/**
	 * Test that PreferencesFormPreSave hook has correct data:
	 *  - user Object is passed
	 *  - oldUserOptions contains previous user options (before save)
	 *  - formData and User object have set up new properties
	 *
	 * @see https://phabricator.wikimedia.org/T169365
	 * @covers ::submitForm
	 */
	public function testPreferencesFormPreSaveHookHasCorrectData() {
		$oldOptions = [
			'test' => 'abc',
			'option' => 'old'
		];
		$newOptions = [
			'test' => 'abc',
			'option' => 'new'
		];
		$configMock = new HashConfig( [
			'HiddenPrefs' => []
		] );
		$form = $this->getMockBuilder( PreferencesFormOOUI::class )
			->disableOriginalConstructor()
			->getMock();

		$userMock = $this->getMockBuilder( User::class )
			->disableOriginalConstructor()
			->getMock();

		$userMock->expects( $this->exactly( 2 ) )
			->method( 'setOption' )
			->withConsecutive(
				[ 'test', $newOptions[ 'test' ] ],
				[ 'option', $newOptions[ 'option' ] ]
			);
		$userMock->method( 'isAllowed' )->willReturnCallback(
			static function ( $permission ) {
				return $permission === 'editmyprivateinfo' || $permission === 'editmyoptions';
			}
		);
		$userMock->method( 'isAllowedAny' )->willReturnCallback(
			static function ( ...$permissions ) {
				foreach ( $permissions as $perm ) {
					if ( $perm === 'editmyprivateinfo' || $perm === 'editmyoptions' ) {
						return true;
					}
				}
				return false;
			}
		);

		$form->method( 'getModifiedUser' )
			->willReturn( $userMock );

		$form->method( 'getContext' )
			->willReturn( $this->context );

		$form->method( 'getConfig' )
			->willReturn( $configMock );

		$userOptionsLookupMock = $this->createUserOptionsLookupMock( $oldOptions );

		$this->setTemporaryHook( 'PreferencesFormPreSave',
			function ( $formData, $form, $user, &$result, $oldUserOptions )
				use ( $newOptions, $oldOptions, $userMock ) {
					$this->assertSame( $userMock, $user );
					foreach ( $newOptions as $option => $value ) {
						$this->assertSame( $value, $formData[ $option ] );
					}
					foreach ( $oldOptions as $option => $value ) {
						$this->assertSame( $value, $oldUserOptions[ $option ] );
					}
					$this->assertTrue( $result );
			}
		);

		/** @var DefaultPreferencesFactory $factory */
		$factory = TestingAccessWrapper::newFromObject(
			$this->getPreferencesFactory( [ 'userOptionsLookup' => $userOptionsLookupMock ] )
		);
		$factory->saveFormData( $newOptions, $form, [] );
	}

	/**
	 * The rclimit preference should accept non-integer input and filter it to become an integer.
	 *
	 * @covers ::saveFormData
	 */
	public function testIntvalFilter() {
		// Test a string with leading zeros (i.e. not octal) and spaces.
		$this->context->getRequest()->setVal( 'wprclimit', ' 0012 ' );
		$user = new User;
		$prefFactory = $this->getPreferencesFactory();
		$form = $prefFactory->getForm( $user, $this->context );
		$form->show();
		$form->trySubmit();
		$this->assertEquals( 12, $user->getOption( 'rclimit' ) );
	}

	/**
	 * @covers ::profilePreferences
	 */
	public function testVariantsSupport() {
		$userMock = $this->getMockBuilder( User::class )
			->disableOriginalConstructor()
			->getMock();
		$userMock->method( 'isAllowed' )->willReturn( true );

		$language = $this->createMock( Language::class );
		$language->method( 'getCode' )
			->willReturn( 'sr' );

		$userOptionsLookupMock = $this->createUserOptionsLookupMock(
			[ 'LanguageCode' => 'sr', 'variant' => 'sr' ], true
		);

		$prefs = $this->getPreferencesFactory( [
			'language' => $language,
			'userOptionsLookup' => $userOptionsLookupMock,
		] )->getFormDescriptor( $userMock, $this->context );
		$this->assertArrayHasKey( 'default', $prefs['variant'] );
		$this->assertEquals( 'sr', $prefs['variant']['default'] );
	}

	/**
	 * @covers ::profilePreferences
	 */
	public function testUserGroupMemberships() {
		$userMock = $this->getMockBuilder( User::class )
			->disableOriginalConstructor()
			->getMock();
		$userMock->method( 'isAllowed' )->willReturn( true );
		$userMock->method( 'isAllowedAny' )->willReturn( true );
		$userMock->method( 'isRegistered' )->willReturn( true );

		$language = $this->createMock( Language::class );
		$language->method( 'getCode' )
			->willReturn( 'en' );

		$userOptionsLookupMock = $this->createUserOptionsLookupMock( [], true );

		$prefs = $this->getPreferencesFactory( [
			'language' => $language,
			'userOptionsLookup' => $userOptionsLookupMock,
		] )->getFormDescriptor( $userMock, $this->context );
		$this->assertArrayHasKey( 'default', $prefs['usergroups'] );
		$this->assertEquals(
			UserGroupMembership::getLink( 'user', $this->context, 'html' ),
			( $prefs['usergroups']['default'] )()
		);
	}

	/**
	 * @coversNothing
	 */
	public function testAllServiceOptionsUsed() {
		$this->assertAllServiceOptionsUsed( [
			// Only used when $wgEnotifWatchlist or $wgEnotifUserTalk is true
			'EnotifMinorEdits',
			// Only used when $wgEnotifWatchlist or $wgEnotifUserTalk is true
			'EnotifRevealEditorAddress',
			// Only used when 'fancysig' preference is enabled
			'SignatureValidation',
		] );
	}

	/**
	 * @param array $userOptions
	 * @param bool $defaultOptions
	 * @return UserOptionsLookup
	 */
	private function createUserOptionsLookupMock( array $userOptions, bool $defaultOptions = false ) {
		$mock = $this->createMock( UserOptionsLookup::class );
		$mock->method( 'getOptions' )->willReturn( $userOptions );
		$services = $this->getServiceContainer();
		if ( $defaultOptions ) {
			$defaults = $services->getMainConfig()->get( 'DefaultUserOptions' );
			$defaults['language'] = $services->getContentLanguage()->getCode();
			$defaults['skin'] = Skin::normalizeKey( $services->getMainConfig()->get( 'DefaultSkin' ) );
			$mock->method( 'getDefaultOptions' )->willReturn( $defaults );
		}
		return $mock;
	}
}
