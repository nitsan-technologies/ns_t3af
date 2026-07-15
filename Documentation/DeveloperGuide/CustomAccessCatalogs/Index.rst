.. include:: ../../Includes.txt

.. _custom-ai-access:

======================
Custom Access Catalogs
======================

Use an AI access catalog provider when your extension needs to expose module access, AI feature permissions, or record-level permissions through AI Foundation.

Runtime enforcement uses the merged catalog from all registered providers. You do not need ``EXTCONF`` wiring for new integrations.

When to use this
----------------

Implement a provider when your extension:

* exposes a backend module that should be controlled per backend user group
* defines AI feature areas checked with ``FeaturePermissionGate``
* stores records that need ``tables_select`` or ``tables_modify`` enforcement

Administrators assign the resulting permissions through **Backend Users** > **Groups** > **Access Lists**. The optional **AI Foundation → AI Access / Roles** wizard reads the same merged catalogs.

Step 1 — Implement the provider
-------------------------------

Implement ``NITSAN\NsT3AF\Contract\AiAccessCatalogProviderInterface`` in your extension.

.. code-block:: php

   namespace MyVendor\MyExt\Access;

   use NITSAN\NsT3AF\Access\Dto\FeatureAccessBindingsDescriptor;
   use NITSAN\NsT3AF\Access\Dto\FeaturePermissionDescriptor;
   use NITSAN\NsT3AF\Access\Dto\ModuleAccessDescriptor;
   use NITSAN\NsT3AF\Access\Dto\RecordPermissionDescriptor;
   use NITSAN\NsT3AF\Contract\AiAccessCatalogProviderInterface;
   use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

   final class MyExtAccessCatalogProvider implements AiAccessCatalogProviderInterface
   {
       public function isAvailable(): bool
       {
           return ExtensionManagementUtility::isLoaded('my_ext');
       }

       public function getExtensionKey(): string
       {
           return 'my_ext';
       }

       public function getCatalogModuleKey(): string
       {
           return 'my_ext';
       }

       public function getModuleAccess(): ?ModuleAccessDescriptor
       {
           return new ModuleAccessDescriptor(
               label: 'My Extension',
               sublabel: 'AI Assistant',
               description: 'Short description for the wizard module card.',
               color: '#2563eb',
               groupMod: 'myvendor_myext_dashboard',
               extension: 'my_ext',
           );
       }

       public function getFeaturePermissions(): array
       {
           return [
               new FeaturePermissionDescriptor(
                   id: 'myFeature',
                   label: 'My AI Feature',
                   description: 'Generate content with AI.',
                   permBase: 'MyExt.Feature',
                   relevantModules: ['my_ext'],
                   group: 'my_ext',
                   extension: 'my_ext',
               ),
           ];
       }

       public function getRecordPermissions(): array
       {
           return [
               new RecordPermissionDescriptor(
                   id: 'myExtRecords',
                   label: 'My Records',
                   tables: ['tx_myext_domain_model_item'],
                   relevantModules: ['my_ext'],
                   relevantFeatures: ['myFeature'],
                   readHelp: 'View records',
                   writeHelp: 'Create and edit records',
                   extension: 'my_ext',
               ),
           ];
       }

       public function getFeatureAccessBindings(): FeatureAccessBindingsDescriptor
       {
           return new FeatureAccessBindingsDescriptor(
               moduleKey: 'my_ext',
               legacyCardPermPrefix: 'tx_myext_dashboard_',
               moduleGroupMod: 'myvendor_myext_',
               tabFeatureMap: ['dashboard' => 'MyExt.Feature'],
           );
       }
   }

Step 2 — Register the provider
------------------------------

Tag the provider in your extension’s ``Configuration/Services.yaml``.

.. code-block:: yaml

   _instanceof:
     NITSAN\NsT3AF\Contract\AiAccessCatalogProviderInterface:
       tags: ['t3af.ai_access_catalog_provider']

   MyVendor\MyExt\Access\:
     resource: '../Classes/Access/'

Step 3 — Gate backend UI and routes
-----------------------------------

Use ``FeaturePermissionGate`` for tabs, cards, and feature areas.

.. code-block:: php

   use NITSAN\NsT3AF\Access\FeaturePermissionGate;
   use TYPO3\CMS\Core\Utility\GeneralUtility;

   $gate = GeneralUtility::makeInstance(FeaturePermissionGate::class);
   if (!$gate->grantsModuleTab($GLOBALS['BE_USER'], 'my_ext', 'content')) {
       // return 403
   }

You can take the reference below for the function definition:

.. code-block:: php

   NITSAN\NsT3AF\Access\FeaturePermissionGate::grantsModuleTab

   <?php
   public function grantsModuleTab(BackendUserAuthentication $user, string $moduleKey, string $tabIdentifier): bool { }
   @param \TYPO3\CMS\Core\Authentication\BackendUserAuthentication $user
   @param string $moduleKey
   @param string $tabIdentifier
   @return bool

For suite child extensions, use the gate method that matches the module family, such as ``grantsT3AiTab``, ``grantsT3AaTab``, ``grantsT3CsTab``, or ``grantsT3AaCard``.

Use ``RecordAccessEnforcer`` before mutating records.

.. code-block:: php

   use NITSAN\NsT3AF\Access\RecordAccessEnforcer;
   use TYPO3\CMS\Core\Utility\GeneralUtility;

   $enforcer = GeneralUtility::makeInstance(RecordAccessEnforcer::class);
   if ($denied = $enforcer->denyUnlessCanModifyCatalogId($GLOBALS['BE_USER'], 'myExtRecords')) {
       return $denied;
   }

Step 4 — Assign permissions
---------------------------

Administrators can assign permissions in two ways.

**Option A — AI Access / Roles wizard**

Open **AI Foundation → AI Access / Roles**, select a backend group, and configure the wizard steps or permission matrix tabs.

**Option B — Native backend groups**

1. Grant the custom feature bit under **Custom module options → AI Assistant**.
2. Grant the backend module under **Modules**.
3. Grant ``tables_select`` and ``tables_modify`` for the relevant tables.

Step 5 — Verify
---------------

* The merged catalog contains your feature and record rows when your extension is loaded.
* A restricted editor without the feature bit cannot open gated tabs or submit protected actions.
* A direct POST without ``tables_modify`` returns a 403 JSON response from ``RecordAccessEnforcer``.

Reference implementations
-------------------------

* ``EXT:ns_t3af_extended/Classes/Access/T3afExtendedAccessCatalogProvider.php``
* ``EXT:ns_t3ai/Classes/Access/T3AiAccessCatalogProvider.php``
* ``EXT:ns_t3aa/Classes/Access/T3AaAccessCatalogProvider.php``
* ``EXT:ns_t3cs/Classes/Access/T3CsAccessCatalogProvider.php``
* ``EXT:ns_t3as/Classes/Access/T3AsAccessCatalogProvider.php``
* ``EXT:ns_t3ac/Classes/Access/T3AcAccessCatalogProvider.php``

Troubleshooting
---------------

**Permissions do not appear in the wizard**

* Confirm the provider is tagged with ``t3af.ai_access_catalog_provider``.
* Confirm ``isAvailable()`` returns ``true``.
* Flush TYPO3 caches so the DI container is rebuilt.

**Backend action is still allowed**

* Confirm the route calls ``FeaturePermissionGate`` or ``RecordAccessEnforcer`` before performing the action.
* Confirm the catalog id used in the enforcer matches the id returned by the provider.

Related documentation
---------------------

* :ref:`Extension Integration <extension-integration>`
* :ref:`AI Permissions <ai-permissions>`
