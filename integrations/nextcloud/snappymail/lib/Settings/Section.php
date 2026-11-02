<?php
namespace OCA\SnappyMail\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class Section implements IIconSection
{
	private IL10N $l;
	private IURLGenerator $urlGenerator;

	public function __construct(IL10N $l, IURLGenerator $urlGenerator)
	{
		$this->l = $l;
		$this->urlGenerator = $urlGenerator;
	}

	public function getID(): string
	{
		return 'snappymail';
	}

	public function getName(): string
	{
		return $this->l->t('SnappyMail');
	}

	public function getPriority(): int
	{
		return 50;
	}

	public function getIcon(): string
	{
		return $this->urlGenerator->imagePath('snappymail', 'favicon-mask.svg');
	}
}
