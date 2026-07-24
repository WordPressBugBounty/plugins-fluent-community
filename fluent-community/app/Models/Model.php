<?php

namespace FluentCommunity\App\Models;

use FluentCommunity\Framework\Database\Orm\Model as BaseModel;

/**
 * @method static static create(array $attributes = [])
 * @method static static|null find(mixed $id, array $columns = ['*'])
 * @method static static findOrFail(mixed $id, array $columns = ['*'])
 * @method static static|null first(array $columns = ['*'])
 * @method static static firstOrFail(array $columns = ['*'])
 */
class Model extends BaseModel
{
    protected $guarded = ['id', 'ID'];

    public function getPerPage()
    {
        if(isset($_REQUEST['per_page'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $this->perPage = (int) $_REQUEST['per_page']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }
        return $this->perPage;
    }

    /**
     * Prepare a date for array / JSON serialization.
     *
     * @param  \DateTimeInterface  $date
     * @return string
     */
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format($this->getDateFormat());
    }
}
