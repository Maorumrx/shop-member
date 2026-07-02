import BranchController from './BranchController'
import ServiceController from './ServiceController'
import TopupOfferController from './TopupOfferController'
import MemberWalletController from './MemberWalletController'
import ShopSettingController from './ShopSettingController'
import MemberController from './MemberController'
import TopupController from './TopupController'
import BookingController from './BookingController'

const Admin = {
    BranchController: Object.assign(BranchController, BranchController),
    ServiceController: Object.assign(ServiceController, ServiceController),
    TopupOfferController: Object.assign(TopupOfferController, TopupOfferController),
    MemberWalletController: Object.assign(MemberWalletController, MemberWalletController),
    ShopSettingController: Object.assign(ShopSettingController, ShopSettingController),
    MemberController: Object.assign(MemberController, MemberController),
    TopupController: Object.assign(TopupController, TopupController),
    BookingController: Object.assign(BookingController, BookingController),
}

export default Admin