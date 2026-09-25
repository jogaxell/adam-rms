<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class InstanceProjectsSidebarSort extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Lets a business choose the order of the project list in the sidebar. The default keeps
     * the order used before the setting existed (delivery start date, then name, then created).
     * Valid values are the keys of bCMS::projectsSidebarSorts().
     */
    public function change(): void
    {
        $this->table('instances')
            ->addColumn('instances_projectsSidebarSort', 'string', [
                'null' => false,
                'default' => 'deliverStart',
                'limit' => 50,
                'comment' => 'Order of the project list in the sidebar - a key of bCMS::projectsSidebarSorts()',
                'after' => 'instances_cableColours'
            ])
            ->save();
    }
}
