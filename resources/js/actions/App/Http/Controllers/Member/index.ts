import DashboardController from './DashboardController'
import BookingController from './BookingController'

const Member = {
    DashboardController: Object.assign(DashboardController, DashboardController),
    BookingController: Object.assign(BookingController, BookingController),
}

export default Member