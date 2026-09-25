<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class InstanceDocumentDefaults extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Lets a business choose which "include" options are ticked by default when printing a project
     * invoice, quote or delivery note. Stored as JSON (documentType => [option => bool]); NULL, or any
     * option left out, keeps the defaults from bCMS::projectDocumentOptions(), which match the dialog's
     * defaults from before the setting existed.
     */
    public function change(): void
    {
        $this->table('instances')
            ->addColumn('instances_documentDefaults', 'text', [
                'null' => true,
                'default' => null,
                'comment' => 'JSON: default include options per project document - see bCMS::projectDocumentDefaults()',
                'after' => 'instances_projectsSidebarSort'
            ])
            ->save();
    }
}
