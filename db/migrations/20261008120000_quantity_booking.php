<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class QuantityBooking extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Lets an asset type be booked by quantity: a project books "N of this type", which creates N normal
     * assignments on free assets marked unbound. Scanning an asset of the type binds one of them to it.
     * Existing assignments default to bound, so nothing changes for them.
     */
    public function change(): void
    {
        $this->table('assetTypes')
            ->addColumn('assetTypes_quantityBooking', 'boolean', [
                'null' => false,
                'default' => 0,
                'comment' => 'Book assets of this type by quantity - the physical asset is chosen when it is scanned',
                'after' => 'assetTypes_value'
            ])
            ->save();
        $this->table('assetsAssignments')
            ->addColumn('assetsAssignments_bound', 'boolean', [
                'null' => false,
                'default' => 1,
                'comment' => '0 = a quantity booking placeholder whose asset can still be exchanged when another asset of the type is scanned',
                'after' => 'assets_id'
            ])
            ->save();
    }
}
