import Component from 'ShopUi/models/component';

const SUBMIT_URL = '/search-ranking-optimizer-widget/submit-relevance-judgment';

export default class SearchRankingOptimizerProductRating extends Component {
    /**
     * The three heart/check/x buttons, in DOM order.
     */
    buttons: HTMLButtonElement[];

    /**
     * The canonical (un-canonicalized on this side — Zed canonicalizes) search term this SRP's results
     * are for, read once from the wrapper's own data attribute rather than re-parsed from the URL.
     */
    searchTerm: string;

    /**
     * The product abstract id this rating widget instance belongs to.
     */
    idProductAbstract: string;

    protected readyCallback(): void {
        this.buttons = <HTMLButtonElement[]>Array.from(this.querySelectorAll(`.${this.jsName}__button`));
        this.searchTerm = this.dataset.searchTerm ?? '';
        this.idProductAbstract = this.dataset.idProductAbstract ?? '';

        if (this.buttons.length === 0) {
            return;
        }

        this.mapEvents();
    }

    protected mapEvents(): void {
        this.buttons.forEach((button) => button.addEventListener('click', () => this.handleClick(button)));
    }

    /**
     * Single-select: clicking a button that is not yet pressed selects it and deselects the other two
     * (visual state only, before the network round-trip — see the scss for how `aria-pressed` drives the
     * heart/x red-family vs. check green colorization). Clicking the ALREADY-pressed button is still sent
     * (idempotent re-submission of the same judgment, matching the backend's own upsert-in-place
     * semantics), rather than treated as a "clear my rating" action — there is no unrated state to return
     * to once a button reads pressed, by design (an admin who wants to change their mind clicks a
     * different button, they do not need a fourth "clear" affordance for that).
     *
     * @param button - the button that was clicked.
     */
    protected handleClick(button: HTMLButtonElement): void {
        const ratingType = button.dataset.ratingType ?? '';
        const previouslyPressed = this.buttons.find((candidate) => candidate.getAttribute('aria-pressed') === 'true');

        this.setPressed(button);
        this.submitJudgment(ratingType, button, previouslyPressed);
    }

    /**
     * @param pressedButton - the button to mark pressed; every other button in this widget is cleared.
     */
    protected setPressed(pressedButton: HTMLButtonElement): void {
        this.buttons.forEach((button) => button.setAttribute('aria-pressed', String(button === pressedButton)));
    }

    /**
     * On a failed or unauthorized submission, reverts the visual state to whatever it was before this
     * click — a customer should never see a colorized button for a judgment that was not actually
     * persisted.
     *
     * @param ratingType - one of SearchRankingOptimizerConfig::RATING_TYPE_* on the PHP side.
     * @param clickedButton - the button that was clicked, for revert-on-failure.
     * @param previouslyPressedButton - whichever button was pressed before this click, if any.
     */
    protected submitJudgment(
        ratingType: string,
        clickedButton: HTMLButtonElement,
        previouslyPressedButton: HTMLButtonElement | undefined,
    ): void {
        const body = new URLSearchParams({
            searchTerm: this.searchTerm,
            idProductAbstract: this.idProductAbstract,
            ratingType,
        });

        fetch(SUBMIT_URL, {
            method: 'POST',
            body,
            credentials: 'same-origin',
        })
            .then((response) => response.json())
            .then((responseData: { isSuccess: boolean }) => {
                if (!responseData.isSuccess) {
                    this.revertPressed(previouslyPressedButton);
                }
            })
            .catch(() => this.revertPressed(previouslyPressedButton));
    }

    /**
     * @param previouslyPressedButton - the button to restore as pressed, or none if nothing was pressed
     * before this click.
     */
    protected revertPressed(previouslyPressedButton: HTMLButtonElement | undefined): void {
        this.buttons.forEach((button) => button.setAttribute('aria-pressed', String(button === previouslyPressedButton)));
    }
}
