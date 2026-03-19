<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaPhpClient\Client;

trait ManagesBrowseDepthTrait
{
    private int $defaultBrowseMaxDepth = 10;

    /**
     * @param int $maxDepth
     * @return static
     */
    public function setDefaultBrowseMaxDepth(int $maxDepth): self
    {
        $this->defaultBrowseMaxDepth = $maxDepth;

        return $this;
    }

    public function getDefaultBrowseMaxDepth(): int
    {
        return $this->defaultBrowseMaxDepth;
    }
}
