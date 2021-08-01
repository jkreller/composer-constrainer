<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\ComposerConstrainer\Business;

use Generated\Shared\Transfer\ComposerConstraintCollectionTransfer;

interface ComposerConstrainerFacadeInterface
{
    /**
     * @api
     *
     * @return \Generated\Shared\Transfer\ComposerConstraintCollectionTransfer
     */
    public function updateConstraints(bool $isStrict = false): ComposerConstraintCollectionTransfer;

    /**
     * @api
     *
     * @param bool $isStrict
     *
     * @return \Generated\Shared\Transfer\ComposerConstraintCollectionTransfer
     */
    public function validateConstraints(bool $isStrict = false): ComposerConstraintCollectionTransfer;
}
