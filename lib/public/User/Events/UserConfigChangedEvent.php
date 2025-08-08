<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2023 Murena SAS <akhil.potukuchi.ext@murena.com>
 *
 * @author Murena SAS <akhil.potukuchi.ext@murena.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCP\User\Events;

use OCP\AppFramework\Attribute\Listenable;
use OCP\EventDispatcher\Event;

#[Listenable(since: '32.0.0')]
class UserConfigChangedEvent extends Event {
	/**
	 * @since 32.0.0
	 */
	public function __construct(
		private string $userId,
		private string $appId,
		private string $key,
		private mixed $value,
		private mixed $oldValue = null,
	) {
		parent::__construct();
	}

	/**
	 * @since 32.0.0
	 */
	public function getUserId(): string {
		return $this->userId;
	}

	/**
	 * @since 32.0.0
	 */
	public function getAppId(): string {
		return $this->appId;
	}

	/**
	 * @since 32.0.0
	 */
	public function getKey(): string {
		return $this->key;
	}

	/**
	 * @since 32.0.0
	 */
	public function getValue(): mixed {
		return $this->value;
	}

	/**
	 * @since 32.0.0
	 */
	public function getOldValue(): mixed {
		return $this->oldValue;
	}
}
